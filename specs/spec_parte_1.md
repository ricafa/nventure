# Especificação da Fase 1 - Esqueleto MVC + Banco de Dados

**Objetivo:** Materializar a árvore de pastas da arquitetura MVC (padrão *fat model*) e o esquema de banco de dados estrutural exigido para o MVP (com PostgreSQL 15+), sem adicionar lógica de negócio aos modelos neste momento.

A fonte da verdade para esta fase são as seções §3 (Modelo de dados) e §5.1 (Convenções) do arquivo `specs/requisitos.md` e a Etapa 1 do `specs/passos_dev.md`.

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

---

## 2. Padrão de Exceções Base e Envelope

Criar classes de Exceção fundamentais do domínio na pasta `app/Exceptions/`:
- `ErroAplicacao` (Classe base)
- `ErroValidacao` (Retorna HTTP 422)
- `ErroConflito` (Retorna HTTP 409)
- `ErroNaoEncontrado` (Retorna HTTP 404)

**Mapeamento Global:**
Modificar o arquivo `bootstrap/app.php` (ou o Exception Handler correspondente) para capturar essas exceções e garantir que todas as respostas da API REST obedeçam à estrutura definida na Seção §5.1 do Requisito:
```json
{ 
  "erro": "CODIGO_DO_ERRO", 
  "mensagem": "Descrição exata do problema." 
}
```

---

## 3. Banco de Dados e Migrations

As seguintes tabelas, constraints e relacionamentos devem ser criados em migrações (separadas por tabela ou unificadas, garantindo integridade e foreign keys corretas):

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
  - Validações de valores: `quantidade >= 0`, `strike > 0`, `premio_pago >= 0`, etc.

### 3.3 Índices Únicos e Específicos
Garantir que a criação englobe todos estes índices do §3.2 e §3.3:
- **Índice Único Parcial PostgreSQL (RN-020):**
  `CREATE UNIQUE INDEX uq_mov_abertura ON posicao_movimentacao(posicao_id) WHERE tipo = 'ABERTURA';`
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
