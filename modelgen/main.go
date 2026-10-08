// Command modelgen generates src/Models/*.php from the model definitions in a
// bellman checkout (services/*/models.go), so the PHP catalog stays in sync
// with the Go library.
//
//	go run modelgen/main.go -bellman ../bellman -out src/Models
//
// It works in two stages: the Go sources are parsed to find every package-level
// gen.Model / embed.Model var, then a throwaway program that imports those
// packages is run inside the bellman module to JSON-encode their actual values.
package main

import (
	"bytes"
	"encoding/json"
	"flag"
	"fmt"
	"go/ast"
	"go/parser"
	"go/token"
	"log"
	"os"
	"os/exec"
	"path/filepath"
	"sort"
	"strconv"
	"strings"
)

// PHP class names for each bellman service package.
var classNames = map[string]string{
	"anthropic": "Anthropic",
	"fireworks": "Fireworks",
	"ollama":    "Ollama",
	"omlx":      "OMLX",
	"openai":    "OpenAI",
	"vertexai":  "VertexAI",
	"vllm":      "VLLM",
	"voyageai":  "VoyageAI",
	"xai":       "XAI",
}

type modelVar struct {
	Name string
	Kind string // "gen" or "embed"
}

type typeConst struct {
	Name  string
	Value string
}

type pkg struct {
	Name     string
	Class    string
	Provider string
	Vars     []modelVar
	Types    []typeConst
}

func main() {
	bellmanDir := flag.String("bellman", "../bellman", "path to a bellman checkout")
	outDir := flag.String("out", "src/Models", "output directory for generated PHP")
	flag.Parse()

	abs, err := filepath.Abs(*bellmanDir)
	if err != nil {
		log.Fatal(err)
	}

	pkgs, err := parsePackages(abs)
	if err != nil {
		log.Fatal(err)
	}

	values, err := evaluate(abs, pkgs)
	if err != nil {
		log.Fatal(err)
	}

	if err := os.MkdirAll(*outDir, 0o755); err != nil {
		log.Fatal(err)
	}
	for _, p := range pkgs {
		if err := os.WriteFile(filepath.Join(*outDir, p.Class+".php"), renderProvider(p, values[p.Name]), 0o644); err != nil {
			log.Fatal(err)
		}
	}
	if err := os.WriteFile(filepath.Join(*outDir, "Catalog.php"), renderCatalog(pkgs), 0o644); err != nil {
		log.Fatal(err)
	}
	log.Printf("generated %d provider classes in %s", len(pkgs), *outDir)
}

func parsePackages(bellmanDir string) ([]pkg, error) {
	files, err := filepath.Glob(filepath.Join(bellmanDir, "services", "*", "models.go"))
	if err != nil {
		return nil, err
	}
	if len(files) == 0 {
		return nil, fmt.Errorf("no services/*/models.go found in %s", bellmanDir)
	}

	var pkgs []pkg
	for _, file := range files {
		f, err := parser.ParseFile(token.NewFileSet(), file, nil, 0)
		if err != nil {
			return nil, err
		}
		p := pkg{Name: f.Name.Name}
		p.Class = classNames[p.Name]
		if p.Class == "" {
			return nil, fmt.Errorf("no PHP class name configured for package %q, add it to classNames", p.Name)
		}

		seen := map[string]string{}
		for _, decl := range f.Decls {
			gd, ok := decl.(*ast.GenDecl)
			if !ok {
				continue
			}
			for _, spec := range gd.Specs {
				vs, ok := spec.(*ast.ValueSpec)
				if !ok || len(vs.Names) != 1 || len(vs.Values) != 1 {
					continue
				}
				name := vs.Names[0].Name
				switch gd.Tok {
				case token.CONST:
					lit, ok := vs.Values[0].(*ast.BasicLit)
					if !ok || lit.Kind != token.STRING {
						continue
					}
					val, err := strconv.Unquote(lit.Value)
					if err != nil {
						return nil, err
					}
					if name == "Provider" {
						p.Provider = val
					} else if isSelector(vs.Type, "embed", "Type") {
						p.Types = append(p.Types, typeConst{Name: name, Value: val})
					}
				case token.VAR:
					cl, ok := vs.Values[0].(*ast.CompositeLit)
					if !ok {
						continue
					}
					var kind string
					switch {
					case isSelector(cl.Type, "gen", "Model"):
						kind = "gen"
					case isSelector(cl.Type, "embed", "Model"):
						kind = "embed"
					default:
						continue
					}
					// PHP method names are case-insensitive.
					if prev, dup := seen[strings.ToLower(name)]; dup {
						return nil, fmt.Errorf("%s: %s and %s collide as PHP method names", p.Name, prev, name)
					}
					seen[strings.ToLower(name)] = name
					p.Vars = append(p.Vars, modelVar{Name: name, Kind: kind})
				}
			}
		}
		if p.Provider == "" {
			return nil, fmt.Errorf("%s: no Provider const found", file)
		}
		pkgs = append(pkgs, p)
	}
	sort.Slice(pkgs, func(i, j int) bool { return pkgs[i].Class < pkgs[j].Class })
	return pkgs, nil
}

func isSelector(expr ast.Expr, pkgName, sel string) bool {
	se, ok := expr.(*ast.SelectorExpr)
	if !ok {
		return false
	}
	id, ok := se.X.(*ast.Ident)
	return ok && id.Name == pkgName && se.Sel.Name == sel
}

// evaluate runs a generated program inside the bellman module that prints the
// JSON encoding of every model var, keyed by package and var name.
func evaluate(bellmanDir string, pkgs []pkg) (map[string]map[string]json.RawMessage, error) {
	var src bytes.Buffer
	src.WriteString("package main\n\nimport (\n\t\"encoding/json\"\n\t\"os\"\n")
	for _, p := range pkgs {
		if len(p.Vars) > 0 {
			fmt.Fprintf(&src, "\t%q\n", "github.com/modfin/bellman/services/"+p.Name)
		}
	}
	src.WriteString(")\n\nfunc main() {\n\tout := map[string]map[string]any{}\n")
	for _, p := range pkgs {
		if len(p.Vars) == 0 {
			continue
		}
		fmt.Fprintf(&src, "\tout[%q] = map[string]any{\n", p.Name)
		for _, v := range p.Vars {
			fmt.Fprintf(&src, "\t\t%q: %s.%s,\n", v.Name, p.Name, v.Name)
		}
		src.WriteString("\t}\n")
	}
	src.WriteString("\t_ = json.NewEncoder(os.Stdout).Encode(out)\n}\n")

	tmp, err := os.MkdirTemp(bellmanDir, "zz_bellman_php_modelgen_")
	if err != nil {
		return nil, err
	}
	defer os.RemoveAll(tmp)
	if err := os.WriteFile(filepath.Join(tmp, "main.go"), src.Bytes(), 0o644); err != nil {
		return nil, err
	}

	cmd := exec.Command("go", "run", "./"+filepath.Base(tmp))
	cmd.Dir = bellmanDir
	cmd.Stderr = os.Stderr
	out, err := cmd.Output()
	if err != nil {
		return nil, fmt.Errorf("evaluating models in %s: %w", bellmanDir, err)
	}

	var values map[string]map[string]json.RawMessage
	if err := json.Unmarshal(out, &values); err != nil {
		return nil, err
	}
	return values, nil
}

type genModel struct {
	Provider                string         `json:"provider"`
	Name                    string         `json:"name"`
	Config                  map[string]any `json:"config"`
	Description             string         `json:"description"`
	InputContentTypes       []string       `json:"input_content_types"`
	InputMaxToken           int            `json:"input_max_token"`
	OutputMaxToken          int            `json:"output_max_token"`
	SupportTools            bool           `json:"support_tools"`
	SupportStructuredOutput bool           `json:"support_structured_output"`
	UsesAdaptiveThinking    bool           `json:"uses_adaptive_thinking"`
}

type embedModel struct {
	Provider         string         `json:"provider"`
	Name             string         `json:"name"`
	Type             string         `json:"type"`
	Description      string         `json:"description"`
	InputMaxTokens   int            `json:"input_max_tokens"`
	OutputDimensions int            `json:"output_dimensions"`
	Config           map[string]any `json:"config"`
}

func renderProvider(p pkg, values map[string]json.RawMessage) []byte {
	var b bytes.Buffer
	b.WriteString(header("Bellman\\Models"))
	b.WriteString("use Bellman\\Embed\\Model as EmbedModel;\nuse Bellman\\Gen\\Model as GenModel;\n\n")
	fmt.Fprintf(&b, "final class %s\n{\n", p.Class)
	fmt.Fprintf(&b, "    public const PROVIDER = %s;\n", phpString(p.Provider))
	if len(p.Types) > 0 {
		b.WriteString("\n    // Provider specific embedding types, use with EmbedModel::withType().\n")
		for _, t := range p.Types {
			fmt.Fprintf(&b, "    public const %s = %s;\n", t.Name, phpString(t.Value))
		}
	}

	for _, v := range p.Vars {
		raw := values[v.Name]
		var args []string
		var ret string
		switch v.Kind {
		case "gen":
			var m genModel
			mustUnmarshal(raw, &m)
			ret = "GenModel"
			args = append(args, "provider: self::PROVIDER", "name: "+phpString(m.Name))
			if m.Description != "" {
				args = append(args, "description: "+phpString(m.Description))
			}
			if len(m.Config) > 0 {
				args = append(args, "config: "+phpValue(m.Config))
			}
			if len(m.InputContentTypes) > 0 {
				args = append(args, "inputContentTypes: "+phpValue(m.InputContentTypes))
			}
			if m.InputMaxToken != 0 {
				args = append(args, fmt.Sprintf("inputMaxToken: %d", m.InputMaxToken))
			}
			if m.OutputMaxToken != 0 {
				args = append(args, fmt.Sprintf("outputMaxToken: %d", m.OutputMaxToken))
			}
			if m.SupportTools {
				args = append(args, "supportTools: true")
			}
			if m.SupportStructuredOutput {
				args = append(args, "supportStructuredOutput: true")
			}
			if m.UsesAdaptiveThinking {
				args = append(args, "usesAdaptiveThinking: true")
			}
		case "embed":
			var m embedModel
			mustUnmarshal(raw, &m)
			ret = "EmbedModel"
			args = append(args, "provider: self::PROVIDER", "name: "+phpString(m.Name))
			if m.Type != "" {
				args = append(args, "type: "+phpString(m.Type))
			}
			if m.Description != "" {
				args = append(args, "description: "+phpString(m.Description))
			}
			if m.InputMaxTokens != 0 {
				args = append(args, fmt.Sprintf("inputMaxTokens: %d", m.InputMaxTokens))
			}
			if m.OutputDimensions != 0 {
				args = append(args, fmt.Sprintf("outputDimensions: %d", m.OutputDimensions))
			}
			if len(m.Config) > 0 {
				args = append(args, "config: "+phpValue(m.Config))
			}
		}
		fmt.Fprintf(&b, "\n    public static function %s(): %s\n    {\n        return new %s(\n", v.Name, ret, ret)
		for _, a := range args {
			fmt.Fprintf(&b, "            %s,\n", a)
		}
		b.WriteString("        );\n    }\n")
	}

	b.WriteString(listMethod("genModels", "GenModel", p, "gen"))
	b.WriteString(listMethod("embedModels", "EmbedModel", p, "embed"))
	b.WriteString("}\n")
	return b.Bytes()
}

func listMethod(method, typ string, p pkg, kind string) string {
	var b strings.Builder
	fmt.Fprintf(&b, "\n    /** @return list<%s> */\n    public static function %s(): array\n    {\n        return [", typ, method)
	n := 0
	for _, v := range p.Vars {
		if v.Kind != kind {
			continue
		}
		fmt.Fprintf(&b, "\n            self::%s(),", v.Name)
		n++
	}
	if n > 0 {
		b.WriteString("\n        ")
	}
	b.WriteString("];\n    }\n")
	return b.String()
}

func renderCatalog(pkgs []pkg) []byte {
	var b bytes.Buffer
	b.WriteString(header("Bellman\\Models"))
	b.WriteString("use Bellman\\Embed\\Model as EmbedModel;\nuse Bellman\\Gen\\Model as GenModel;\n\n")
	b.WriteString("final class Catalog\n{\n")
	for _, kind := range []struct{ method, typ string }{{"genModels", "GenModel"}, {"embedModels", "EmbedModel"}} {
		fmt.Fprintf(&b, "    /** @return array<string, %s> keyed by FQN, e.g. \"OpenAI/gpt-4o\" */\n", kind.typ)
		fmt.Fprintf(&b, "    public static function %s(): array\n    {\n        $models = [];\n", kind.method)
		fmt.Fprintf(&b, "        foreach ([\n")
		for _, p := range pkgs {
			fmt.Fprintf(&b, "            ...%s::%s(),\n", p.Class, kind.method)
		}
		b.WriteString("        ] as $model) {\n            $models[$model->fqn()] = $model;\n        }\n        return $models;\n    }\n\n")
	}
	b.WriteString(`    /** Looks up a known gen model by FQN, e.g. "Anthropic/claude-sonnet-4-5". */
    public static function gen(string $fqn): ?GenModel
    {
        return self::genModels()[$fqn] ?? null;
    }

    /** Looks up a known embed model by FQN, e.g. "VoyageAI/voyage-3.5". */
    public static function embed(string $fqn): ?EmbedModel
    {
        return self::embedModels()[$fqn] ?? null;
    }
}
`)
	return b.Bytes()
}

func header(namespace string) string {
	return "<?php\n\n// Code generated by modelgen from the bellman Go library. DO NOT EDIT.\n\ndeclare(strict_types=1);\n\nnamespace " + namespace + ";\n\n"
}

func mustUnmarshal(raw json.RawMessage, v any) {
	if err := json.Unmarshal(raw, v); err != nil {
		log.Fatal(err)
	}
}

func phpString(s string) string {
	r := strings.NewReplacer(`\`, `\\`, `'`, `\'`)
	return "'" + r.Replace(s) + "'"
}

func phpValue(v any) string {
	switch t := v.(type) {
	case string:
		return phpString(t)
	case bool:
		return strconv.FormatBool(t)
	case float64:
		return strconv.FormatFloat(t, 'f', -1, 64)
	case nil:
		return "null"
	case []string:
		parts := make([]string, len(t))
		for i, s := range t {
			parts[i] = phpString(s)
		}
		return "[" + strings.Join(parts, ", ") + "]"
	case []any:
		parts := make([]string, len(t))
		for i, s := range t {
			parts[i] = phpValue(s)
		}
		return "[" + strings.Join(parts, ", ") + "]"
	case map[string]any:
		keys := make([]string, 0, len(t))
		for k := range t {
			keys = append(keys, k)
		}
		sort.Strings(keys)
		parts := make([]string, len(keys))
		for i, k := range keys {
			parts[i] = phpString(k) + " => " + phpValue(t[k])
		}
		return "[" + strings.Join(parts, ", ") + "]"
	}
	log.Fatalf("unsupported value %T in model definition", v)
	return ""
}
