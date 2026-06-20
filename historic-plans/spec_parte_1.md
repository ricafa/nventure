# Especificação da Fase 1 - Esqueleto MVC + Banco de Dados

**Objetivo:** Materializar a árvore de pastas da arquitetura MVC (padrão *fat model*) e o esquema de banco de dados estrutural exigido para o MVP (com PostgreSQL 15+), sem adicionar lógica de negócio aos modelos neste momento.

A fonte da verdade para esta fase são as seções §3 (Modelo de dados) e §5.1 (Convenções) do arquivo `specs/requisitos.md` e a Fase 1 do `specs/passos_dev.md`.

---

## 1. Estrutura de Diretórios e Configurações Base

Deverão ser criadas (se ainda não existirem) as seguintes pastas de organização lógica da aplicação MVC:

- `app/Models/` e `app/Models/Concerns/` (Models Eloquent e Traits de cálculo)
- `app/Services/` e `app/Services/Dados/` (Regras de negócio e DTOs/Read Models)
- `app/Facades/` (Fachadas para facilitar a injeção/uso dos Services)
- `app/Support/Csv/` (Para ingestão do arquivo de preços)
- `app/Exceptions/` (Exceções de aplicação estruturadas)
- `app/Policies/` (Políticas de segurança do modelo de dados)

**Ações de Configuração:**
- No arquivo `app/Providers/AppServiceProvider.php`, deixar preparada (mesmo que com comentários ou retornos vazios inicialmente) a região de registro de Bindings/Singletons de serviços que darão origem às Facades.
- **Persistência das pastas no Git (DoD §5.2):** o Git não versiona diretórios vazios. Toda pasta que não receber um arquivo concreto nesta fase (ex.: `app/Models/Concerns/`, `app/Services/Dados/`, `app/Facades/`, `app/Support/Csv/`, `app/Policies/`) deve conter um arquivo `.gitkeep` (ou um arquivo-semente inicial) para que a topologia de diretórios realmente exista no repositório.

---

## 2. Padrão de Exceções Base e Envelope

Criar classes de Exceção fundamentais do domínio na pasta `app/Exceptions/`:
- `ErroAplicacao` (Classe base) — **deve fixar um status HTTP default (500)** para que qualquer
  erro de aplicação não mapeado por uma subclasse tenha status determinístico, em vez de ficar indefinido.
- `ErroValidacao` (Retorna HTTP 422)
- `ErroConflito` (Retorna HTTP 409)
- `ErroNaoEncontrado` (Retorna HTTP 404)

**Mapeamento Global (D-605):**
Modificar o arquivo `bootstrap/app.php` (ou o Exception Handler correspondente) para capturar essas exceções e garantir que todas as respostas da API REST obedeçam à estrutura definida na Seção §5.1 do Requisito:
```json
{ 
  "erro": "CODIGO_DO_ERRO", 
  "mensagem": "Descrição exata do problema." 
}
```

> **Escopo do envelope (somente JSON):** o envelope §5.1 é exclusivo da **API REST**. O mapeamento
> só deve produzir resposta JSON quando a requisição esperar JSON (`$request->expectsJson()` ou
> rotas do grupo `api`). Para requisições web/Livewire, manter o tratamento de erro padrão do
> Laravel (páginas de erro) — caso contrário as telas server-rendered quebram.

> **Teste do contrato nesta fase:** como ainda não há endpoints (controllers chegam na Fase 4), o
> mapeamento não é exercitado por nenhum teste de feature. Para travar o contrato §5.1 desde já,
> incluir um **teste unitário** que renderiza uma `ErroValidacao` via o handler e afirma tanto o
> *shape* `{ "erro": ..., "mensagem": ... }` quanto o status HTTP 422.

---

## 3. Banco de Dados e Migrations

As seguintes tabelas, constraints e relacionamentos devem ser criados em migrações (separadas por tabela ou unificadas, garantindo integridade e foreign keys corretas):

### 3.0 Convenções obrigatórias do esquema (aderência a §3.2)

- **Tipo de PK/FK (decisão fixada):** §3.2 usa `SERIAL` (INTEGER) para as PKs e `INTEGER` para as
  FKs. O default do Laravel (`$table->id()` + `foreignId()`) gera **BIGINT unsigned**, o que provoca
  *type mismatch* na FK. Para esta fase, **usar `$table->increments('id')` (INTEGER) e FKs
  `unsignedInteger`/`integer`** de modo a casar com `SERIAL`. Todo o esquema deve ser consistente
  (PK e FK do mesmo tipo).
- **Timestamps / coluna `criado_em`:** nenhuma tabela de §3.2 usa o par convencional
  `created_at`/`updated_at`. **Não usar `$table->timestamps()`.** Criar `criado_em TIMESTAMP NOT NULL
  DEFAULT NOW()` (e `processado_em` em `mtm_diario`, `iniciado_em`/`finalizado_em` em
  `motor_execucao`) exatamente como em §3.2.
- **DEFAULTs de §3.2 a preservar:** `posicao.status DEFAULT 'ABERTA'`, `produto.ativo DEFAULT TRUE`,
  `usuario.ativo DEFAULT TRUE`, `posicao_otc.premio_otc DEFAULT 0`, e os `criado_em DEFAULT NOW()`.
- **Comportamento `ON DELETE` por FK (sustenta RN-010a e o `DELETE /posicoes/{id}` "somente sem MtM"):**
  - **`ON DELETE CASCADE`** nas tabelas-filhas de `posicao`: `posicao_futuro`, `posicao_ndf`,
    `posicao_opcao`, `posicao_opcao_perna`, `posicao_otc` e `posicao_movimentacao`.
  - **`RESTRICT`/`NO ACTION`** (sem cascade) em `mtm_diario.posicao_id` → `posicao` e
    `mtm_diario.preco_ref_id` → `preco_referencia`: um preço/posição com MtM **não** pode ser
    apagado silenciosamente (RN-010a).
- **`motor_execucao.falhas` é `JSONB`** (não `text`/`json`) — é uma das razões de o MVP exigir
  Postgres. As colunas `finalizado_em`, `total_posicoes`, `sucessos` e `falhas` são **nullable**
  (NULL enquanto a execução está em andamento), conforme §3.2.9.
- **Colunas reservadas:** `preco_referencia.vol_implicita` e `preco_referencia.taxa_juros`
  (`NUMERIC(8,4)`, NULL) devem constar das migrations mesmo sem uso no MVP.

### 3.1 Lista de Tabelas
Criar migrações do Laravel para cada uma:
1. `produto`
2. `preco_referencia`
3. `posicao`
4. `posicao_futuro`
5. `posicao_movimentacao`
6. `posicao_ndf`
7. `posicao_opcao`
8. `posicao_opcao_perna`
9. `posicao_otc`
10. `mtm_diario`
11. `motor_execucao`
12. `usuario` *(Nota: A tabela já teve uma migração base na Fase 0. Deve-se garantir que as colunas e checks do modelo definitivo de `usuario` estejam 100% aderentes à especificação)*.

> **Consolidação da `usuario` (decisão fixada — Caminho A):** a tabela `usuario` é a única que já
> existe parcialmente (migration mínima da Fase 0, `spec_parte_0.md` §4.4). Como o projeto está
> **pré-produção (sem dados reais)**, a consolidação se dá **editando-se a própria migration mínima
> da Fase 0** para refletir §3.2.10 integralmente — e **não** criando uma migration de `ALTER`
> separada. Isso mantém uma única fonte de verdade do esquema da `usuario` e um `migrate:fresh`
> determinístico. A edição deve garantir:
> - Tipos/lengths de §3.2.10: `login VARCHAR(60)`, `nome VARCHAR(120)`, `senha_hash VARCHAR(255)`,
>   `perfil VARCHAR(20)`.
> - **`CHECK (perfil IN ('OPERADOR','GESTOR','ADMIN'))`** — constraint a nível de banco (ponto mais
>   propenso a ter ficado de fora da versão mínima).
> - `UNIQUE(login)`, `ativo BOOLEAN NOT NULL DEFAULT TRUE`.
> - Convenção `criado_em TIMESTAMP NOT NULL DEFAULT NOW()` **sem** `$table->timestamps()` (ver §3.0),
>   igual às demais 11 tabelas.
>
> O `DatabaseSchemaTest` (§4) deve exercitar o CHECK de `perfil` (inserir valor inválido e esperar
> `QueryException`), comprovando que a consolidação criou a constraint.

### 3.2 Constraints e Tipos Críticos
- Utilizar colunas do tipo numérico exato do PostgreSQL (`NUMERIC(18, 6)`, `NUMERIC(18, 4)`, `NUMERIC(18, 2)` e `NUMERIC(8, 4)`) como definidos em §3.2. **Nunca** utilizar FLOAT.
- **Constraints CHECK de negócio (Devem estar a nível de banco de dados):**
  - Instrumento (`posicao.instrumento`): IN ('FUTURO', 'NDF', 'OPCAO', 'OTC')
  - Mercado (`posicao.mercado`): IN ('BOLSA', 'BALCAO')
  - Lado (`posicao.lado`, `posicao_opcao_perna.lado`): IN ('COMPRADO', 'VENDIDO')
  - Status (`posicao.status`): IN ('ABERTA', 'ENCERRADA', 'VENCIDA')
  - Tipo Movimentação (`posicao_movimentacao.tipo`): IN ('ABERTURA', 'AUMENTO', 'REDUCAO')
  - Tipo Opção (`posicao_opcao_perna.tipo_opcao`): IN ('CALL', 'PUT')
  - Estilo Opção (`posicao_opcao_perna.estilo`): IN ('EUROPEIA', 'AMERICANA')
  - Perfil (`usuario.perfil`): IN ('OPERADOR', 'GESTOR', 'ADMIN')
  - **CHECKs de valor (lista exata de §3.2 — não usar "etc." genérico):**
    - `posicao.quantidade >= 0`
    - `posicao_movimentacao.quantidade > 0` e `posicao_movimentacao.preco > 0`
    - `posicao_opcao_perna.sequencia > 0`, `strike > 0`, `premio_pago >= 0`, `quantidade > 0`
    - **`posicao_otc.premio_otc` aceita valor negativo (§3.2.7) — NÃO criar CHECK de sinal** sobre essa coluna.

### 3.3 Índices Únicos e Específicos
Garantir que a criação englobe todos estes índices do §3.2 e §3.3:
- **Índice Único Parcial PostgreSQL (RN-020):**
  `CREATE UNIQUE INDEX uq_mov_abertura ON posicao_movimentacao(posicao_id) WHERE tipo = 'ABERTURA';`
- **Índices UNIQUE de coluna única (§3.2):**
  - `UNIQUE(nome)` na tabela `produto`
  - `UNIQUE(login)` na tabela `usuario`
- **Índices UNIQUE Compostos:**
  - `UNIQUE(produto_id, data_preco)` na tabela `preco_referencia`
  - `UNIQUE(posicao_id, data_calculo)` na tabela `mtm_diario`
  - `UNIQUE(posicao_id, sequencia)` na tabela `posicao_opcao_perna`
- **Índices de Performance (B-Tree normais e com Ordenação):**
  - `CREATE INDEX idx_preco_produto_data ON preco_referencia(produto_id, data_preco DESC);`
  - `CREATE INDEX idx_posicao_status ON posicao(status) WHERE status = 'ABERTA';`
  - `CREATE INDEX idx_posicao_produto ON posicao(produto_id);`
  - `CREATE INDEX idx_mtm_posicao_data ON mtm_diario(posicao_id, data_calculo DESC);`
  - `CREATE INDEX idx_mtm_data ON mtm_diario(data_calculo);`
  - `CREATE INDEX idx_mov_posicao_data ON posicao_movimentacao(posicao_id, data_movimentacao, id);`

*(É altamente recomendado criar essas declarações diretamente usando `$table->index(...)`, `$table->unique(...)` nas Migrations ou usar comandos `DB::statement(...)` onde o Schema builder não tiver suporte (como no caso do Partial Unique Index e dos arrays de indexação reversa)).*

---

## 4. Teste de Validação (Pest)

Nesta etapa, o motor ainda não foi desenvolvido, portanto a verificação focará na camada estrutural (Migrations) de forma unitária/feature-based.

Deverá ser criado um teste de migração do banco (ex: `tests/Feature/DatabaseSchemaTest.php`) para comprovar a robustez e integridade do Schema.

> **Banco-alvo do teste:** este teste **precisa rodar contra PostgreSQL** (`postgres_test`), não
> SQLite — índice único parcial (`uq_mov_abertura WHERE ...`), CHECKs e `JSONB` não existem no SQLite.
> Como ainda **não há Models** nesta fase, os casos de violação de CHECK/UNIQUE devem usar `INSERT`
> cru (`DB::table(...)->insert(...)`) e afirmar que uma `QueryException` é lançada (não há validação
> de Model para acionar).

O teste deve abranger:
- Verifica se rodar o comando `php artisan migrate:fresh` finaliza com sucesso.
- Se a restrição `uq_mov_abertura` (Índice Único Parcial) permite e barra adequadamente mais de uma linha de `ABERTURA` para a mesma posição.
- Se os CHECKs de constraint barram valores inadequados de ENUM (ex: Status, Tipos).
- Se as constraints de banco UNIQUE lançam erros SQL como esperado quando dados duplicados entram.

---

## 5. Critérios de Aceite (Definition of Done - DoD)

1. `php artisan migrate:fresh` não aponta nenhum erro e constrói todas as tabelas perfeitamente no ambiente com `PostgreSQL`.
2. A pasta do projeto reflete a topologia de diretórios e arquitetura desejada (`app/Models`, `Concerns`, `Services`, `Dados`, `Facades`, `Support`, `Exceptions`, `Policies`).
3. Arquivo `bootstrap/app.php` foi modificado para retornar a padronização JSON nas exceções bases configuradas (`ErroAplicacao`, `ErroValidacao`, `ErroConflito`, `ErroNaoEncontrado`).
4. Os testes escritos validam perfeitamente e confirmam o funcionamento dos índices (`uq_mov_abertura`), constraints e Unique Keys.
5. As rotinas da integração contínua (.github/workflows) passam em todas as suítes (Test, PHPStan Nível 8, Pint).
