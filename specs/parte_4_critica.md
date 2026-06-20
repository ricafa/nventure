# Crítica construtiva — `spec_parte_4.md` (Módulo Produtos & Preços)

> **Autor da revisão:** engenheiro de software (arquitetura).
> **Base da análise:** `specs/spec_parte_4.md` confrontado com `specs/requisitos.md` (v1.6, fonte
> da verdade), `specs/passos_dev.md` (Fase 4) e o **código já existente** no repositório
> (`app/Models/`, `app/Exceptions/`, `app/Providers/AppServiceProvider.php`,
> `app/Models/Concerns/ConverteDecimais.php`).
> **Natureza:** observações para endurecer a spec antes da implementação. Não altera regras de
> negócio nem contratos — onde aponto divergência, `requisitos.md` prevalece.

## Veredito geral

A spec está **bem estruturada e madura**: decisões numeradas (D-401..D-410), mapa de arquivos ×
responsabilidade, DoD verificável, riscos tabelados e separação limpa entre validação estrutural
(Form Request) e regra de negócio (Service). O respeito ao *fat model*, o ponto único de extensão
(`FontePrecos`) e os testes "sem banco" do importador estão corretos e alinhados ao `CLAUDE.md`.

Os problemas se concentram em **(a)** um erro técnico concreto e verificável contra o código,
**(b)** robustez sob concorrência, **(c)** adequação do CSV ao público brasileiro e **(d)** o
modelo de ameaça do CWE-1236, que está deslocado. Abaixo, por severidade.

---

## 1. Bloqueador — erro técnico verificável

### B-1. `ConverteDecimais::paraFloat()` não é chamável como método estático de classe

A spec recomenda, em vários pontos (§4 intro, §4.3 nota, tabela de riscos), converter decimais com
`ConverteDecimais::paraFloat(...)`. Mas o arquivo real é:

```php
// app/Models/Concerns/ConverteDecimais.php
trait ConverteDecimais
{
    public static function paraFloat(string|int|float|null $valor): float { ... }
    public static function arredonda(float $valor, int $casas = 4): float { ... }
}
```

É um **trait**, não uma classe utilitária. Em PHP, `ConverteDecimais::paraFloat(...)` **não é
válido** (traits não são unidades chamáveis diretamente; isso gera erro). Para usar no
`ServicoPrecos`/`ImportadorPrecosCsv` seria preciso `use ConverteDecimais;` na classe e chamar
`self::paraFloat(...)`/`$this->paraFloat(...)`.

**Consequência prática:** o exemplo de `validarRegras` "escapa" disso fazendo `(float)` direto
(o que funciona), mas o texto da spec instrui o desenvolvedor a um caminho que não compila.

**Recomendação:** ou (1) `use ConverteDecimais` nos consumidores e chamar via `self::`/`$this->`,
ou (2) promover `ConverteDecimais` a uma classe `final` com métodos estáticos (mais natural para uso
fora de Models, que é exatamente o caso de um Service/Importador). A opção (2) é mais limpa, mas
muda a decisão D-MVC-2 — então registrar isso explicitamente. Padronizar a spec para **um** caminho.

---

## 2. Altos — robustez e adequação

### A-1. Unicidade por "SELECT-então-INSERT" é *race condition* → 500 em vez de 409

`ServicoProdutos::criar`/`atualizar` (nome único) e `ServicoPrecos::validarRegras` (RN-007) fazem
`->exists()` e depois `create()`. Sob duas requisições concorrentes, ambas passam no `exists()` e
a segunda viola o `UNIQUE` do banco → **`QueryException` não tratada → 500** (e potencial vazamento
de detalhe), justamente o oposto do 409 prometido na DoD (item 2) e em D-404.

A spec até reconhece "o UNIQUE do banco é o backstop", mas **não captura** a violação para traduzir
ao envelope. O pré-`SELECT` racy ainda dobra o número de queries sem fechar a janela.

**Recomendação:** tornar o `UNIQUE` o caminho primário — envolver o `create()` em `try/catch` de
`QueryException` e, no SQLSTATE `23505` (unique_violation do Postgres), lançar `ErroConflito`. O
pré-check pode permanecer como atalho de UX, mas o `catch` é o que garante o 409 de forma correta e
atômica. Vale para `produto.nome` **e** RN-007.

### A-2. CSV assume locale en-US — o público é brasileiro (Excel pt-BR)

`APP_LOCALE=pt_BR` e o público-alvo (mesa de risco, agronegócio brasileiro — `requisitos.md` §1.3)
exportam CSV do **Excel pt-BR**, que por padrão usa:

- **delimitador `;`** (não `,`), e
- **separador decimal `,`** (ex.: `1450,50`).

O `ImportadorPrecosCsv` usa `SplFileObject::READ_CSV` com delimitador padrão `,` e valida números
com `is_numeric()`. Resultado: um arquivo legítimo gerado no Excel brasileiro será **inteiramente
rejeitado** — cada linha cai em "Número de colunas diferente de 4" (por causa do `;`) ou
"não numéricos" (por causa de `1450,50`). Isso quebra o caso de uso mais comum na prática.

**Recomendação:** decidir conscientemente e documentar no template/tela:
- mínimo: deixar **explícito** na UI que o formato exigido é vírgula-delimitado e ponto-decimal
  (ASCII/RFC 4180), e oferecer um CSV-modelo para download; **ou**
- melhor: detectar/aceitar `;` como delimitador e normalizar `,`→`.` em colunas numéricas.

Mesmo que a escolha seja "formato US estrito", isso precisa estar na spec como **decisão**, porque
hoje está implícito e contradiz o público.

### A-3. BOM UTF-8 quebra a validação de cabeçalho

Excel salva CSV-UTF8 com **BOM** (`EF BB BF`). A primeira célula vira `﻿produto_id`, que
`!== 'produto_id'` → "Cabeçalho inválido", abortando todo o upload. Outra fonte silenciosa de
rejeição total para arquivos legítimos.

**Recomendação:** remover BOM antes de comparar o cabeçalho (ou `ltrim` do `\xEF\xBB\xBF` na
primeira célula). Adicionar um caso de teste unitário "arquivo com BOM".

### A-4. CWE-1236 está no lugar errado do *threat model* (e tem um bypass)

A injeção de fórmula (CWE-1236) é um risco de **exportação/exibição** — materializa-se quando o
sistema **gera** um CSV contendo texto controlado por atacante e um usuário o abre no Excel. Na
**ingestão** da Parte 4, as quatro colunas são **estritamente tipadas** (int / date ISO / decimal):
uma célula `=cmd()` em `produto_id` já falharia em `ctype_digit`; em `preco`/`cambio`, em
`is_numeric`. Ou seja, a defesa anti-fórmula no importador é, na prática, **inerte** para essas
colunas — protege algo que a tipagem já barra.

O risco **real** de CWE-1236 neste sistema é o **texto livre que será re-exportado**: `produto.nome`,
`posicao.observacoes`, `contraparte`, `motor_execucao.disparado_por` — campos que entram por
formulário, são persistidos e depois saem nos **relatórios CSV/PDF da Fase 7** (`formato=csv`,
`requisitos.md` §5.2.5). Esse caminho hoje **não tem dono** na spec: a Parte 4 "gasta" o
endurecimento na ingestão tipada e a Fase 7 só "reaproveita o endurecimento da Fase 4" — mas o
endurecimento que importa (sanitização na **escrita do CSV de saída**) não foi especificado em
lugar nenhum.

Além disso, há um **bypass concreto**: a checagem de prefixo perigoso roda **antes do `trim`**
(`in_array($celula[0], ...)`), então `" =SOMA()"` (espaço à esquerda) passa — e o Excel ainda
interpreta a fórmula após o espaço.

**Recomendações:**
1. Manter a checagem (defesa em profundidade barata), mas aplicá-la **após `trim`** para fechar o
   bypass.
2. Registrar explicitamente que o controle CWE-1236 **autoritativo** é na **geração** de CSV
   (Fase 7) e em qualquer campo de texto livre — e abrir essa pendência ali, não dá para "herdar"
   da Parte 4 algo que a Parte 4 não produz.
3. Reconhecer na spec que, para as 4 colunas tipadas, a tipagem é a defesa primária; a regra de
   prefixo é redundante (não errada).

### A-5. O contrato `FontePrecos` — o "único ponto de extensão" — é fracamente tipado

`FontePrecos::ler(): iterable<int, array<string, mixed>>` com chaves mágicas `_linha`/`_erro`
misturadas às chaves de negócio é o oposto do que se espera do **único** contrato que "sobrevive"
no sistema (CLAUDE.md). Custos:

- **PHPStan nível 8 fica cego** dentro do laço: `$linha['_erro'] ?? null`, `(int) $linha['_linha']`,
  `(string) $linha['_erro']` são casts em `mixed` — exatamente o tipo de incerteza que o nível 8
  deveria eliminar. O `array<string,mixed>` empurra a fragilidade para o Service.
- **Acoplamento semântico** por convenção de string (`_erro != null ⇒ inválida`), não por tipo.

**Recomendação:** modelar a saída como um **DTO tipado** — ex.: `LinhaImportada` com
`int $linha`, `?string $erro`, e os campos de negócio tipados (ou um par
`LinhaValida`/`LinhaInvalida`). O `ler(): iterable<int, LinhaImportada>` mantém o streaming e dá ao
Service um contrato verificável. Isso valoriza a decisão D-406 em vez de enfraquecê-la com arrays
associativos. Custo baixo, alto retorno para o ponto de extensão que a arquitetura mais preza.

---

## 3. Médios — semântica, consistência e performance

### M-1. "Escala inválida → rejeição" (D-407c / DoD-3) **diverge do código**: ele arredonda

D-407(c) diz "decimal com escala ≤ coluna" e a DoD item 3 promete que "tipo/escala inválidos viram
**rejeição**". Mas o código faz `round((float) $preco, 6)` — ou seja, **coage silenciosamente**
(arredonda) em vez de rejeitar. Um preço `1450.1234567` (7 casas) entra como `1450.123457`, não é
reportado como rejeitado. Spec, DoD e implementação precisam concordar: ou rejeita (e há teste para
isso), ou documenta que arredonda — não os três discursos ao mesmo tempo.

Correlato: converter `NUMERIC(18,6)` por `(float)` perde precisão para valores com muitos dígitos
significativos (float ~15–16). Para dado financeiro, considerar validar a escala por **string/regex**
e persistir a string original, em vez de passar por `float`.

### M-2. `importar` sem transação + persistência linha-a-linha → import parcial silencioso

Cada `PrecoReferencia::create()` é autocommit isolado. O limite de linhas
(`if ($numero - 1 > MAX_LINHAS) { yield erro; return; }`) **aborta após já ter persistido as 5.000
primeiras**. Resultado para um arquivo de 5.001 linhas: 5.000 preços gravados + 1 linha de erro,
sem rollback. Pior: um re-upload do mesmo arquivo (depois de corrigir) cai na rejeição por duplicata
(D-408), porque metade já está no banco.

**Recomendação:** decidir e documentar a semântica de atomicidade. Opções: (a) contar/validar
limites **antes** de persistir (duas passadas ou pré-checagem de tamanho/linhas), ou (b) envolver o
lote numa transação com rollback em estouro de limite. No mínimo, deixar claro no relatório/Tela que
o import é "best-effort por linha, não atômico".

### M-3. N+1 no importador — performance para 5.000 linhas

`validarRegras` faz **2 SELECTs por linha** (existência do produto + existência do par
produto/data) e o insert é individual. Para o teto de 5.000 linhas isso é ~10.000 SELECTs + 5.000
INSERTs — tensão direta com §9.1 (embora a meta de 30s seja do motor, upload lento é UX ruim).

**Recomendação:** pré-carregar o conjunto de `produto_id` válidos uma vez (um `pluck`), validar
existência em memória; para a unicidade, considerar `insert ... on conflict do nothing` + relatório,
ou ao menos um índice já existente (há `UNIQUE(produto_id, data_preco)`). Não precisa otimizar
agora, mas a spec deveria reconhecer o custo e não cravar o padrão N+1 como definitivo.

### M-4. `mimes:csv,txt` é frágil — pode barrar uploads legítimos

A detecção de MIME de CSV é notoriamente inconsistente (CSV frequentemente chega como
`text/plain`, às vezes `application/vnd.ms-excel`). `mimes:` valida pela extensão **adivinhada a
partir do MIME**, então arquivos `.csv` reais podem ser rejeitados pelo Form Request antes mesmo de
chegar ao importador. Avaliar `extensions:csv,txt` (valida a extensão do nome) combinado com a
validação de conteúdo que o importador já faz (cabeçalho exato). Documentar a escolha.

### M-5. PUT vs PATCH e a dupla personalidade do `SalvarProdutoRequest`

`apiResource('produtos')` mapeia **PUT e PATCH** para `update`. A spec usa um único
`SalvarProdutoRequest` com regras `required` (store) e diz "no update, `sometimes`". Uma mesma
classe não alterna sozinha — precisa ramificar por `$this->isMethod('POST')` (ou requests
separados). Além disso, semanticamente PUT é *full replace* (campos ausentes deveriam ser
limpos/obrigatórios) e `sometimes` o transforma em PATCH. **Recomendação:** explicitar a estratégia
(ramificar regras por método, ou usar requests distintos para store/update) e decidir se o contrato
é PUT-replace ou PATCH-merge.

### M-6. Upload sempre 200, mesmo com cabeçalho inválido / arquivo grande

A semântica de lote da RN-010 (200 com `{aceitas, rejeitadas[]}`) é correta para **linhas mistas**.
Mas um arquivo com **cabeçalho inválido** ou **acima do limite** não é "lote com algumas linhas
ruins" — é uma requisição malformada, e devolver 200 com `aceitas: 0` pode confundir o cliente da
API. Vale distinguir: erro estrutural do arquivo (cabeçalho/limite/tamanho) → talvez **422**;
linhas individuais ruins → 200 com relatório. É uma decisão de contrato que a spec deveria fixar
(hoje está implícito em "sempre 200").

---

## 4. Baixos — clareza e pontas soltas

- **BX-1. Rótulo RN-006 incorreto.** O comentário `// FK / RN-006-like` em `validarRegras` confunde:
  RN-006 é "indexador do OTC corresponde a um produto" (regra de **posições**, Fase 5). A checagem
  aqui é só integridade de FK `preco_referencia.produto_id`. Trocar o rótulo para evitar
  rastreabilidade enganosa no apêndice RN × Fase.

- **BX-2. `arredonda()` (default 4 casas) não é reutilizado** para preço/câmbio (escala 6) — o
  importador chama `round(...,6)` direto. Coerente com o erro B-1; ao corrigir a borda decimal,
  unificar via o helper passando `casas: 6`.

- **BX-3. CSV só com cabeçalho** (zero linhas de dados) produz `total: 0` sem qualquer aviso —
  considerar uma mensagem "nenhuma linha de dados" para UX.

- **BX-4. Reativação de produto** só é possível via `PUT` com `ativo: true`; a spec descreve a
  inativação (D-405) mas não menciona o caminho de volta. Explicitar (mesmo que seja "via update").

- **BX-5. Resources "decimais como número"** (§4.7) versus precisão: serializar `NUMERIC(18,6)`
  como número JSON via `(float)` colide com exatidão financeira para valores grandes. §5.1 pede
  "número sem aspas", mas vale registrar a perda de precisão como trade-off aceito do MVP (ou
  serializar com escala fixa).

- **BX-6. Paginação ausente em `listar()`.** `ServicoProdutos::listar`/`ServicoPrecos::listar`
  retornam `Collection` inteira. §9.1 exige listagem paginada (50/página, <500ms) e a Fase 12 trata
  performance — mas o **contrato** dos endpoints `index` muda ao introduzir paginação depois
  (envelope `data`/`meta`). Decidir agora se o contrato já nasce paginado evita quebra futura.

- **BX-7. Sequenciamento Sanctum.** D-402 deixa a API sob `auth:sanctum` mas a emissão de token só
  vem na Fase 10 — então a API REST é, na prática, **inacessível a cliente real** até lá (só
  testável via `Sanctum::actingAs`). É aceitável e está reconhecido, mas vale uma frase na spec de
  que a "primeira fatia da API" não é consumível externamente ainda — para não criar expectativa.

---

## 5. Pontos fortes (para preservar)

- Separação **estrutural (Form Request) × negócio (Service)** com dois formatos de 422 conscientes
  (D-403/D-404) — bem pensada; o único ajuste é unificar a comunicação ao cliente.
- **Streaming real** com `SplFileObject` + limites de bytes/linhas — direção correta contra
  exaustão de memória.
- **Soft delete idempotente** por `ativo` (D-405) coerente com o esquema (não há `deleted_at`).
- **DTO `ResultadoImportacao`** com `paraArray()` — fronteira limpa entre Service e HTTP/UI.
- **`FontePrecos` como único ponto de extensão** preservado (só falta tipar a saída — A-5).
- **Testes do importador sem banco** — fiel ao §8.4 e à pureza do cálculo.
- Mapa de arquivos, checklist e tabela de riscos tornam a parte **executável** com pouca ambiguidade.

---

## 6. Ações recomendadas (prioridade)

| # | Severidade | Ação |
|---|---|---|
| B-1 | Bloqueador | Corrigir o uso de `ConverteDecimais` (trait): `use` + `self::`/`$this->`, ou promover a classe estática. Padronizar a spec. |
| A-1 | Alto | `try/catch QueryException` (SQLSTATE 23505) → `ErroConflito` para nome único e RN-007 (fechar a *race*). |
| A-2 | Alto | Decidir/documentar delimitador `;` e decimal `,` (Excel pt-BR) — aceitar ou exigir formato com modelo na UI. |
| A-3 | Alto | Remover BOM antes de comparar cabeçalho + teste. |
| A-4 | Alto | Mover o controle CWE-1236 autoritativo para a **geração** de CSV (Fase 7) e texto livre; corrigir bypass aplicando o filtro **após `trim`**. |
| A-5 | Alto | Tipar a saída de `FontePrecos::ler()` com um DTO (`LinhaImportada`) em vez de `array<string,mixed>` com chaves mágicas. |
| M-1 | Médio | Alinhar D-407c/DoD-3/código: rejeitar escala inválida **ou** documentar que arredonda. |
| M-2 | Médio | Definir atomicidade do `importar` (pré-checar limites antes de persistir, ou transação). |
| M-3 | Médio | Reduzir N+1 (pré-carregar produtos válidos; reconhecer custo na spec). |
| M-4 | Médio | Reavaliar `mimes:` → `extensions:` para uploads de CSV. |
| M-5 | Médio | Definir estratégia PUT/PATCH e a ramificação de regras do `SalvarProdutoRequest`. |
| M-6 | Médio | Fixar no contrato: erro estrutural de arquivo (cabeçalho/limite) = 422 vs lote = 200. |
| BX-1..7 | Baixo | Ajustes de rótulo, helper, UX de CSV vazio, reativação, precisão de Resource, paginação de contrato e nota de sequenciamento Sanctum. |

> **Nota final.** Nenhuma destas observações exige mudar regras de negócio (§7), modelo de dados
> (§3) ou contratos de API (§5) do `requisitos.md`. São endurecimentos de **implementação e de
> redação da spec**. As de maior impacto real para este produto específico são **A-2/A-3** (CSV
> brasileiro) e **A-1** (409 sob concorrência), porque afetam o caminho feliz do operador na vida
> real; **B-1** é o único item que impede o código de seguir a spec ao pé da letra.
