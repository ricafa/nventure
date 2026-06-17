# Prompt — Especificação dos testes unitários

Você é um(a) engenheiro(a) de qualidade/testes. Leia **integralmente** o
`requisitos.md` (v1.4, com foco em §4 — domínio, §7 — regras e §8 — testes) e o
`ARCHITECTURE.md` (stack Laravel, estrutura e convenções) e crie um arquivo
`UNIT-TESTS.md` na raiz do projeto: a **especificação detalhada dos testes
unitários** do **NeverVenture**, servindo de plano e checklist da suíte de unidade.

**Contexto** (confirme lendo os documentos-base): o coração do sistema é um
**domínio rico e determinístico em PHP puro** (sem Laravel/Eloquent) — cálculo de
MtM polimórfico, preço médio por *replay* de movimentações e P&L realizado (§4).
Essa camada é o alvo natural dos testes unitários; meta de cobertura **≥ 90%**
(§8.4). Há exemplos em **Pest** no §8.1 a reutilizar e ampliar.

## Ferramentas e padrões (obrigatórios)

- **Runner/cobertura:** **Pest** (sobre PHPUnit) + cobertura via Xdebug/PCOV.
- **Datasets:** `->with([...])` do Pest para varrer cenários (comprado/vendido ×
  mercado a favor/contra etc.).
- **Helpers/builders:** funções utilitárias (em `tests/Helpers.php`, carregadas pelo
  `tests/Pest.php`) para montar `Posicao`/`Futuro`/`Opcao` com defaults sensatos.
- **Property-based (opcional, recomendado):** **Eris** (QuickCheck para PHP) para
  invariantes (ex.: RN-024).
- **Mutation testing (opcional):** **Infection** sobre `app/Dominio/`.
- **Princípios:** `it('...')`/`test('...')` em **português**; AAA; um conceito por
  teste; **sem I/O, sem framework, sem banco**; determinístico (`DateTimeImmutable`);
  `toBe` (estrito) para valores exatos e `toEqualWithDelta` quando houver ponto
  flutuante; valores esperados **calculados à mão e comentados**.

## Conteúdo do `UNIT-TESTS.md` (nesta ordem)

1. **Objetivo e escopo** — o que é unitário (a camada de **domínio** do §4: classes
   PHP puras + o motor com *test doubles*) e o que **fica fora** (Eloquent/HTTP/
   Livewire/Sanctum/CSV → Feature, §8.2; a hidratação §4.5 depende de banco). Os
   testes de domínio **não** usam `RefreshDatabase` nem o framework. Reafirmar as
   metas do §8.4.

2. **Organização e convenções** — estrutura `tests/Unit/Dominio/` espelhando
   `app/Dominio/` (`ARCHITECTURE.md` §3); nomes em **português** nas descrições do
   `it()`; uso de datasets, helpers e `DateTimeImmutable`; isolamento.

3. **Estratégia de dados de teste** — tabela de valores de referência calculados à
   mão (reaproveitar §8.1: pm 1410, realizado +1.500, MtM 6.000); precisão
   (`toEqualWithDelta`), sinais em posições vendidas. **Premissa:** arredondamento
   monetário fica na borda (serviço/API), não no domínio.

4. **Especificação por unidade** — para cada classe, casos com entrada e resultado:
   - **`Posicao` (§4.2):** `sinal()` (COMPRADO +1, VENDIDO −1); `plRealizado()`
     padrão 0 nas subclasses sem movimentação.
   - **`Futuro` + `Movimentacao` (§4.3.1, RN-020..024):** abertura; aumento → média
     ponderada (RN-021); redução **mantém** o pm e gera realizado (RN-023); redução
     em **vendido** inverte o sinal; redução **total** zera (RN-022); **sem
     movimentações** → *fallback* `precoEntrada`/`quantidade`; ordenação cronológica
     (ABERTURA primeiro no empate); `calcularMtm()` = (preço − precoMedio) ×
     quantidadeAtual × sinal.
   - **`NDF` (§4.3.2):** comprado/vendido × a favor/contra; convenção cambial.
   - **`Opcao` + `Perna` (§4.3.3):** valor intrínseco CALL/PUT; soma das pernas;
     straddle, collar, bull call spread; perna comprada **e** vendida.
   - **`OTC` (§4.3.4):** preço efetivo (indexador + prêmio) e MtM; comprado/vendido.
   - **`MotorMtm` (§4.4) com *test doubles*:** fakes dos **contratos**
     (`RepositorioPosicoes/Precos/Mtm`); idempotência (RN-013); ausência de preço
     marca falha e continua (RN-012); conversão BRL (RN-015); `plAcumulado = mtmBrl +
     plRealizado × cambioBrl` (RN-023); confirmação de que **não há `if` por tipo**.

5. **Casos de borda** — limites de quantidade/preço; movimentações fora de ordem;
   posição encerrada não processada (RN-011); arredondamento; e (opcional, Eris) a
   invariante RN-024.

6. **Cobertura e critérios de aceite** — metas §8.4; Pest `--coverage --min=90` no
   domínio quebrando o build; cada RN de cálculo com ≥ 1 teste; **Infection**
   opcional para força dos `expect`.

7. **Rastreabilidade RN → testes** — tabela mapeando regras/fórmulas (RN-021/022/
   023/024 e MtM por tipo) aos nomes dos testes.

8. **Exemplo de teste** — pelo menos um exemplo completo em **Pest** no estilo do
   §8.1, incluindo um caso **parametrizado por dataset** e um uso de *helper*/builder.

## Diretrizes finais

- Idioma do documento: **português**. Tom: técnico, objetivo, direto.
- Reutilize e amplie os exemplos do §8.1; **todo** valor esperado calculado à mão e
  comentado.
- Mantenha o escopo **unitário**: nada de banco, HTTP, Livewire ou arquivos (isso é
  Feature/integração, §8.2). Cite § e RN. Para lacunas reais, registre **premissas
  explícitas**.
