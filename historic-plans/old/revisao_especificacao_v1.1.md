# Relatório de revisão técnica — `especificacao_mvp_risco_mercado.md` v1.1

**Data da revisão:** 2026-06-12
**Versão revisada:** 1.1 → correções aplicadas na versão **1.2**
**Resultado:** 12 achados — 3 críticos (lógica financeira), 4 estruturais, 5 menores. Todos corrigidos na v1.2.

As localizações por seção referem-se à v1.1; nenhuma fórmula de cálculo mudou — as correções são de documentação, modelo de dados e consistência entre seções.

---

## Críticos — lógica financeira

### 1. Dupla conversão cambial no NDF

- **Localização:** §4.3.2 (`NDF.calcular_mtm`) + §4.4 (motor) + RN-015.
- **Severidade:** crítico.
- **Problema:** o motor converte todo MtM para BRL via `mtm_brl = mtm_moeda_orig * preco.cambio_brl`. Para um NDF cambial (nocional em USD, taxa em BRL/USD), `(taxa_mercado − taxa_contratada) × nocional` **já resulta em BRL** — a multiplicação pelo câmbio duplicaria a conversão, inflando o MtM pelo fator do câmbio (~5×).
- **Correção aplicada:** documentada a convenção (decisão do usuário): a moeda do NDF cambial é cadastrada como `produto` (ex.: "Dólar USD/BRL") com `moeda_cotacao = 'BRL'` e `cambio_brl = 1`, sendo `preco_fechamento` a própria taxa de câmbio do dia — a multiplicação do motor torna-se neutra, sem caso especial no código. Convenção registrada em §1.4 (nova premissa), §4.3.2 (nota), §4.4 (nota) e RN-015.

### 2. `pl_acumulado` sempre igual a `mtm_valor`

- **Localização:** §4.4 (linha `pl_acumulado = mtm_brl`) + coluna em §3.2.8.
- **Severidade:** crítico (semântica de dado financeiro ambígua).
- **Problema:** a coluna `pl_acumulado` em `mtm_diario` é, no código do motor, sempre uma cópia de `mtm_valor`, sem que o documento explique se isso é intencional — um leitor poderia supor erro ou implementar lógica divergente.
- **Correção aplicada:** redundância tornada intencional e declarada: no MVP não há realização parcial nem liquidação diária, logo o P&L acumulado desde a abertura coincide com o MtM; a coluna existe para divergir na Fase 2 sem mudança de esquema. Notas adicionadas na descrição da coluna (§3.2.8), em comentário no código do motor e em nota após §4.4.

### 3. Contradição no prêmio das pernas de opção

- **Localização:** §3.2.6a (`posicao_opcao_perna.premio_pago`) vs descrição da coluna vs RN-004.
- **Severidade:** crítico (constraint contradiz a descrição; risco de cadastro com sinal duplicado).
- **Problema:** o CHECK é `premio_pago >= 0`, mas a descrição dizia "negativo permitido para pernas vendidas". Como a fórmula de `Perna.calcular_mtm` já aplica a direção via `sinal` (campo `lado`), um prêmio negativo em perna vendida inverteria o efeito duas vezes.
- **Correção aplicada:** mantido o CHECK ≥ 0; descrição corrigida para "Prêmio unitário (magnitude, sempre ≥ 0); a direção é dada pelo campo `lado` da perna". RN-004 já estava coerente e não mudou.

---

## Estruturais

### 4. Tabela de execuções do motor inexistente

- **Localização:** §5.2.4 (API retorna `execucao_id`, expõe `GET /motor/execucoes`) e §2.3 (auditoria por design) vs §3 (modelo de dados sem a tabela).
- **Severidade:** estrutural.
- **Problema:** a API e o princípio de auditoria pressupõem persistência das execuções do motor, mas o modelo de dados não tinha tabela correspondente.
- **Correção aplicada:** adicionada a tabela `motor_execucao` (§3.2.9: `id`, `data_calculo`, `disparado_por`, `iniciado_em`, `finalizado_em`, `total_posicoes`, `sucessos`, `falhas JSONB`), com `usuario` renumerada para §3.2.10. Adicionada coluna opcional `execucao_id` (FK) em `mtm_diario`, relação no diagrama lógico §3.1 e nota de referência cruzada em §5.2.4. A tabela cobre todos os campos da resposta da API (`execucao_id`, `data_calculo`, `posicoes_processadas`, `sucessos`, `falhas`).

### 5. Diagrama UML §4.1 desatualizado

- **Localização:** §4.1.
- **Severidade:** estrutural.
- **Problema:** o diagrama ainda mostrava `Opcao` com `tipo, strike, premio_pago` — atributos que a v1.1 moveu para `Perna`; a classe `Perna` não aparecia.
- **Correção aplicada:** diagrama redesenhado com `Opcao { nome_estrutura, pernas[] }` e a classe `Perna { tipo_opcao, estilo, strike, premio_pago, quantidade, lado }` em composição (1..N).

### 6. Versão do cabeçalho divergente do changelog

- **Localização:** linha 3 ("Versão: 1.0") vs §12 (changelog registrava 1.1).
- **Severidade:** estrutural (controle de versão do documento).
- **Correção aplicada:** cabeçalho atualizado para 1.2 e nova linha no changelog com data 2026-06-12 resumindo esta revisão.

### 7. Convenção de `quantidade`/`lado` da posição mãe para OPCAO indefinida

- **Localização:** §3.2.3 (`posicao` exige `quantidade > 0` e `lado`) vs RN-004c (dados operacionais vivem nas pernas).
- **Severidade:** estrutural.
- **Problema:** para opções, o documento não dizia o que preencher na posição mãe — os payloads de exemplo usavam `quantidade: 1` sem que isso fosse regra.
- **Correção aplicada:** convenção formalizada em nota em §3.2.3 e nova **RN-004e**: `quantidade = 1` na mãe e `lado` meramente informativo (direção predominante da estratégia); o cálculo usa exclusivamente quantidade e lado das pernas.

---

## Menores

### 8. RN-006 mal redigida

- **Localização:** §7.1.
- **Problema:** "OTC com mesmo indexador deve referenciar um produto cadastrado" — redação truncada/ambígua.
- **Correção aplicada:** "o `indexador` da posição OTC deve corresponder a um produto cadastrado no sistema".

### 9. `DELETE /precos/{id}` sem restrição de integridade

- **Localização:** §5.2.2.
- **Problema:** um preço referenciado por `mtm_diario.preco_ref_id` (FK) não pode ser removido, mas o endpoint não documentava a restrição.
- **Correção aplicada:** nova **RN-010a** em §7.2 e nota no endpoint: exclusão rejeitada com `409 Conflict` quando o preço já foi usado em cálculo.

### 10. `PUT /posicoes/{id}/encerrar` com verbo inadequado

- **Localização:** §5.2.3.
- **Problema:** encerrar é uma ação sobre o recurso, não substituição de representação — semântica REST pede `POST`.
- **Correção aplicada:** endpoint alterado para `POST /posicoes/{id}/encerrar`.

### 11. `variacao_dia` mistura variação de preço e de câmbio

- **Localização:** §3.2.8 + §4.4.
- **Problema:** a variação diária é calculada comparando valores em BRL de dias com câmbios potencialmente diferentes, misturando efeito-preço e efeito-câmbio sem que isso estivesse declarado.
- **Correção aplicada:** nota na descrição da coluna: comportamento aceito no MVP; a decomposição preço × câmbio fica para a Fase 2.

### 12. Colisão de colunas no SQL ilustrativo

- **Localização:** §4.5 (`SELECT p.*, pf.*, pn.*, po.nome_estrutura, pot.*`).
- **Problema:** `pf.preco_entrada` e `pot.preco_entrada` colidem, além de múltiplas colunas `posicao_id` — o exemplo não era executável de forma não ambígua.
- **Correção aplicada:** SELECT reescrito com colunas explícitas e aliases (`fut_preco_entrada`, `otc_preco_entrada`).

---

## Verificações pós-correção

- Cabeçalho (1.2) ↔ changelog §12: consistentes.
- Diagrama lógico §3.1 ↔ tabelas §3.2: inclui `motor_execucao ──< mtm_diario`; FK `execucao_id` presente.
- UML §4.1 ↔ classes §4.2/§4.3: `Opcao`/`Perna` alinhados ao código.
- APIs §5 ↔ modelo de dados §3 ↔ regras §7: `motor_execucao` cobre a resposta de `GET /motor/execucoes`; RN-010a referenciada no endpoint de preços.
- Exemplos numéricos dos testes §8.1: inalterados e corretos — nenhuma fórmula mudou.
