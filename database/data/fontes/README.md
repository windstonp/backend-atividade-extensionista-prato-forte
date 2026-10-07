# Fontes do catálogo ampliado (Plano 11B, RN52)

CSV intermediários no formato `name,category,kcal,protein,carbs,fat,source` (valores por 100 g da fonte), lidos por `php artisan catalog:import`.

| Arquivo | Fonte | Download |
|---|---|---|
| `taco.csv` | TACO — Tabela Brasileira de Composição de Alimentos, 4ª ed. revisada e ampliada (NEPA/UNICAMP, 2011), Tabela 1 (centesimal) | PDF em https://www.cfn.org.br/wp-content/uploads/2017/03/taco_4_edicao_ampliada_e_revisada.pdf, baixado em 2026-10-07 |
| `ibge-pof.csv` | IBGE — Pesquisa de Orçamentos Familiares 2008–2009: Tabelas de Composição Nutricional dos Alimentos Consumidos no Brasil (2011), Tabela 1 | PDF em https://biblioteca.ibge.gov.br/visualizacao/livros/liv50002.pdf, baixado em 2026-10-07 |

## Como foram gerados

1. Baixe os PDFs para `originais/` (fora do Git).
2. `pdftotext -layout taco-4ed.pdf taco.txt` e `pdftotext -layout ibge-pof-2008-2009.pdf ibge.txt` (poppler-utils).
3. `python3 scripts/taco_para_csv.py` e `scripts/ibge_para_csv.py` (rodando de dentro de `originais/`; as funções `gerar()` escrevem os CSV).

Regras:
- **TACO:** só a parte "Centesimal" da Tabela 1; categoria = título da seção; energia (kcal), proteína, lipídeos e carboidrato. "Tr", "NA" e "*" ficam como estão (o importador zera traço e descarta linha sem kcal). A cachaça (nº 472) vem sem macros e fica de fora. Números de nota de rodapé no fim do nome foram removidos.
- **IBGE:** só preparação "Não se aplica" (o alimento como comprado/consumido); variantes "orgânico" ficam de fora (repetem os valores). Categorias do IBGE traduzidas para as da TACO (`categoria()` em `ibge_para_csv.py`). "-" vira vazio (0 no importador).
- O importador compara slug e nome normalizado: o que já existe não entra de novo; a TACO é importada antes, então o IBGE só completa.

Conferido: 10 linhas da TACO e 10 do IBGE contra o texto dos PDFs, em 2026-10-07 (script de conferência no histórico do Plano 11B). A conferência nutricional fica para P5.
