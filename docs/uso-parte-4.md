# Guia de uso — Produtos & Preços (Parte 4)

Como usar, na prática, as funcionalidades entregues na Parte 4: cadastro de
**produtos** (commodities) e lançamento de **preços de referência** (manual e por
**upload de CSV**), tanto pela **API REST** quanto pelas **telas web (Livewire)**.

> **Fonte da verdade dos contratos:** `specs/requisitos.md` §5.2.1/§5.2.2 (API), §6.1
> (telas), §5.1 (envelope de erro). Decisões de implementação: `specs/spec_parte_4.md`.

---

## 1. Pré-requisitos

- Stack no ar: `docker compose up -d` (app em `http://localhost:8000`).
- Para as **telas**, faça login (credenciais de demonstração — senha `password`):
  `admin`, `gestor` ou `operador`.
- Para a **API**, é preciso estar autenticado (Sanctum). Veja a seção 2.

> **Importante (sequenciamento):** a emissão de **tokens** de API só chega na **Fase 10**
> (RBAC & Autenticação). Hoje a API está protegida por `auth:sanctum`, mas **não há
> endpoint de emissão de token** — na prática, o caminho consumível por humanos é pelas
> **telas web** (sessão). A API é exercitada pelos testes via `Sanctum::actingAs(...)`. Os
> exemplos `curl` abaixo passam a valer integralmente quando a Fase 10 expuser tokens; a
> autorização **por perfil** (OPERADOR/GESTOR/ADMIN) também é da Fase 10.

---

## 2. Autenticação da API

A API vive sob o prefixo **`/api/v1`** e exige o header de autenticação Sanctum:

```
Authorization: Bearer <TOKEN>
Accept: application/json
Content-Type: application/json
```

- **Datas:** ISO 8601 (`YYYY-MM-DD`).
- **Decimais:** número sem aspas (ex.: `1450.5`).
- **Erros de negócio (envelope §5.1):** `{ "erro": "CÓDIGO", "mensagem": "..." }`.
- **Erros de validação estrutural (nativo do Laravel):** `{ "message": "...", "errors": { "campo": ["..."] } }`.

| Situação | Status | Corpo |
|---|---|---|
| Validação estrutural (campo ausente/ tipo errado) | `422` | `{message, errors}` |
| Regra de negócio violada (preço ≤ 0 etc.) | `422` | `{erro, mensagem}` |
| Conflito (nome/preço duplicado, preço em uso) | `409` | `{erro, mensagem}` |
| Recurso inexistente | `404` | `{erro, mensagem}` |

---

## 3. Produtos — API (§5.2.1)

Campos do produto: `nome` (≤60), `unidade` (≤20), `bolsa_ref` (≤20),
`moeda_cotacao` (3 letras, ISO), `ativo` (boolean).

### Listar
```bash
curl -s http://localhost:8000/api/v1/produtos \
  -H "Authorization: Bearer $TOKEN" -H "Accept: application/json"

# Apenas ativos:
curl -s "http://localhost:8000/api/v1/produtos?apenas_ativos=1" \
  -H "Authorization: Bearer $TOKEN" -H "Accept: application/json"
```

### Detalhar
```bash
curl -s http://localhost:8000/api/v1/produtos/1 \
  -H "Authorization: Bearer $TOKEN" -H "Accept: application/json"
```

### Criar (`201`)
```bash
curl -s -X POST http://localhost:8000/api/v1/produtos \
  -H "Authorization: Bearer $TOKEN" -H "Accept: application/json" -H "Content-Type: application/json" \
  -d '{
        "nome": "Soja CBOT",
        "unidade": "bushel",
        "bolsa_ref": "CBOT",
        "moeda_cotacao": "USD"
      }'
```
Nome duplicado → **409** (`ERRO_CONFLITO`).

### Atualizar (PATCH-merge — campos ausentes são preservados)
```bash
curl -s -X PUT http://localhost:8000/api/v1/produtos/1 \
  -H "Authorization: Bearer $TOKEN" -H "Accept: application/json" -H "Content-Type: application/json" \
  -d '{ "nome": "Soja CBOT (nov)" }'
```

### Inativar (soft delete — `204`)
```bash
curl -s -X DELETE http://localhost:8000/api/v1/produtos/1 \
  -H "Authorization: Bearer $TOKEN" -H "Accept: application/json"
```
> `DELETE` **não apaga**: marca `ativo = false` (D-405). O produto some das opções de
> novas posições, mas permanece em listagens/relatórios históricos. Para **reativar**, use
> `PUT` com `{"ativo": true}`. A operação é idempotente (inativar já inativo → `204`).

---

## 4. Preços de referência — API (§5.2.2)

Campos do preço: `produto_id` (int), `data_preco` (`YYYY-MM-DD`),
`preco_fechamento` (> 0), `cambio_brl` (> 0).

### Listar (filtros opcionais)
```bash
curl -s "http://localhost:8000/api/v1/precos?produto_id=1&data_inicio=2026-05-01&data_fim=2026-05-31" \
  -H "Authorization: Bearer $TOKEN" -H "Accept: application/json"
```

### Lançar 1 preço (`201`)
```bash
curl -s -X POST http://localhost:8000/api/v1/precos \
  -H "Authorization: Bearer $TOKEN" -H "Accept: application/json" -H "Content-Type: application/json" \
  -d '{
        "produto_id": 1,
        "data_preco": "2026-05-23",
        "preco_fechamento": 1450.50,
        "cambio_brl": 5.12
      }'
```
Regras aplicadas no serviço:
- **RN-007** — já existe preço para `(produto_id, data_preco)` → **409**.
- **RN-008/009** — `preco_fechamento`/`cambio_brl` ≤ 0 → **422**.
- Produto inexistente → **422**.

### Remover (`204`)
```bash
curl -s -X DELETE http://localhost:8000/api/v1/precos/10 \
  -H "Authorization: Bearer $TOKEN" -H "Accept: application/json"
```
> **RN-010a:** se o preço já foi usado em cálculo de MtM (`mtm_diario.preco_ref_id`), a
> remoção é bloqueada com **409**. Corrija o cenário pelo motor antes de remover.

---

## 5. Upload de preços em lote (CSV)

### Endpoint
```bash
curl -s -X POST http://localhost:8000/api/v1/precos/upload \
  -H "Authorization: Bearer $TOKEN" -H "Accept: application/json" \
  -F "arquivo=@precos.csv"
```

### Formato do arquivo (canônico §5.2.2)
Cabeçalho **exato** e delimitador `,` com decimal `.`:
```csv
produto_id,data_preco,preco_fechamento,cambio_brl
1,2026-05-23,1450.50,5.12
2,2026-05-23,72.30,5.12
```

Também é aceito o **CSV do Excel pt-BR** (delimitador `;`, decimal `,`, com ou sem BOM):
```csv
produto_id;data_preco;preco_fechamento;cambio_brl
1;2026-05-23;1450,50;5,12
```

### Resposta (sempre `200`) — relatório do lote (RN-010)
O lote **não aborta** por linhas ruins: as válidas entram e as inválidas são reportadas.
```json
{
  "total": 4,
  "aceitas": 1,
  "rejeitadas": [
    { "linha": 3, "motivo": "Já existe preço para esse produto nessa data." },
    { "linha": 4, "motivo": "Produto inexistente." },
    { "linha": 5, "motivo": "Preço deve ser maior que zero." }
  ]
}
```

### Validações e limites do importador
- **Cabeçalho** diferente do esperado → o lote inteiro é rejeitado (uma linha de erro).
- **Tipos:** `produto_id` inteiro, `data_preco` no formato ISO, `preco`/`cambio` numéricos.
- **Escala decimal:** arredondada para **6 casas** (coerção, não rejeição).
- **Duplicata** `(produto_id, data_preco)` → linha rejeitada (RN-007), não sobrescreve.
- **Segurança (CWE-1236):** células iniciadas por `= + - @` (TAB/CR), após `trim`, são
  rejeitadas (anti-formula-injection).
- **Limites:** arquivo ≤ **2 MB** e ≤ **5.000 linhas** de dados.

---

## 6. Telas web (Livewire, §6.1)

Acesse autenticado em `http://localhost:8000`.

### Produtos — `/produtos`
- Tabela com `nome`, `unidade`, `bolsa_ref`, `moeda_cotacao` e status (Ativo/Inativo).
- **Novo produto** abre o formulário; **Editar** carrega o registro; **Inativar** (com
  confirmação) faz o soft delete. Erros de negócio (ex.: nome duplicado) aparecem como
  mensagem amigável no formulário (sem stack trace).

### Preços — `/precos`
- **(a) Lançar preço:** formulário manual (`produto`, `data`, `fechamento`, `câmbio`).
- **(b) Importar CSV:** selecione o arquivo e clique **Importar**; o relatório mostra
  *"N aceitas / M rejeitadas"* e a tabela de rejeições (`linha` + `motivo`).
- **Listagem filtrável** por produto e período, com ação **Remover** (respeita a RN-010a:
  preço usado em MtM exibe mensagem de bloqueio).

---

## 7. Como testar este módulo

```bash
# Suíte completa do módulo (API + Livewire + importador)
docker compose exec -e DB_HOST=postgres_test -e DB_PORT=5432 app \
  vendor/bin/pest tests/Feature/Produtos tests/Feature/Precos tests/Unit/Csv

# Só o importador CSV (sem banco)
docker compose exec -e DB_HOST=postgres_test -e DB_PORT=5432 app \
  vendor/bin/pest tests/Unit/Csv/ImportadorPrecosCsvTest.php
```

Veja mais variações em `README.md` → *Comandos de Desenvolvimento e Qualidade*.

---

## 8. Referências

- `specs/requisitos.md` — §5.1 (envelope), §5.2.1/§5.2.2 (contratos), §6.1 (telas), §7.2 (RN-007..010a).
- `specs/spec_parte_4.md` — decisões de implementação (D-401..D-412) e DoD.
- `CLAUDE.md` — seção *Módulo Produtos & Preços (Parte 4)*.
