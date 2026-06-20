# Especificação técnica — MVP de gestão de risco de mercado para commodities

**Versão:** 1.7
**Escopo:** Mark-to-market diário, posição consolidada e P&L para instrumentos derivativos sobre commodities.

---

## 1. Visão geral

### 1.1 Propósito

O sistema fornece, para a mesa de risco de uma empresa exposta a commodities, a marcação a mercado diária de todas as posições em derivativos. Ao final de cada pregão, o operador obtém o valor justo de cada posição, a variação do dia, o P&L acumulado e a exposição líquida consolidada por produto.

### 1.2 Escopo do MVP

O MVP cobre exclusivamente o ciclo de risco de mercado pós-trade. As posições são cadastradas manualmente ou importadas, os preços de referência são alimentados manualmente ou via CSV, e o motor processa o cálculo diário sob demanda ou por agendamento.

**Está dentro do escopo:**

- Cadastro de produtos (commodities) e preços de referência diários.
- Cadastro manual de posições nos quatro tipos de instrumento: futuro, NDF, opção (call/put) e OTC.
- Movimentações de posições futuro (aumentos e reduções), com preço médio ponderado e apuração de P&L realizado nas reduções.
- Motor de mark-to-market diário com cálculo polimórfico por tipo de instrumento.
- Geração e persistência do histórico de MtM, variação diária e P&L acumulado.
- Relatórios de posição aberta, P&L diário/acumulado e exposição líquida por produto.
- Interface web para cadastro, consulta e disparo do processo diário.

**Está fora do escopo (próximas iterações):**

- Integração com feeds de mercado em tempo real (Bloomberg, Refinitiv, B3 market data).
- Cálculo de gregas (delta, gamma, vega, theta, rho) e precificação por Black-Scholes/Black-76.
- VaR, CVaR, stress testing e simulação Monte Carlo.
- Limites de risco automatizados e workflow de aprovação de breach.
- Integração com sistemas de trading (ETRM/CTRM) ou ERP.
- Compras, vendas físicas e gestão de estoque.
- Análise de fretes, clima ou dados meteorológicos.
- Multi-tenant, multi-empresa ou consolidação em grupos.
- Compliance regulatório (BACEN, CVM, IFRS 9, IFRS 13).

### 1.3 Público-alvo

Empresas do agronegócio, trading houses e indústrias com exposição estruturada a commodities (soja, milho, café, açúcar, boi, etanol, petróleo) que operam derivativos para hedge ou especulação e precisam acompanhar o valor de mercado diário do portfólio.

### 1.4 Premissas

- Os preços de referência (fechamento de bolsa, indicadores) são lançados manualmente ou via upload de CSV ao final do dia.
- O câmbio do dia é único e armazenado junto com o preço de referência.
- Para NDF cambial (ex.: USD/BRL), a moeda é cadastrada como um `produto` (ex.: "Dólar USD/BRL") com `moeda_cotacao = 'BRL'` e `cambio_brl = 1`; o `preco_fechamento` desse produto é a própria taxa de câmbio do dia. Isso garante que o resultado do cálculo já saia em BRL sem dupla conversão (ver §4.3.2 e RN-015).
- A volatilidade implícita e a taxa de juros, embora persistidas, **não são utilizadas no MVP** — ficam reservadas para iterações futuras.
- Cada posição tem exatamente um produto subjacente e uma contraparte.
- O motor é idempotente: pode ser executado várias vezes para a mesma data sem produzir registros duplicados.

---

## 2. Arquitetura

### 2.1 Visão de módulos

O sistema é organizado em quatro módulos lógicos:

1. **Módulo de preços** — gerencia o cadastro de produtos e preços de referência diários.
2. **Módulo de posições** — gerencia o cadastro de posições nos quatro tipos de instrumento.
3. **Módulo motor MtM** — núcleo de cálculo. Executa o processamento diário, persistindo os resultados.
4. **Módulo de relatórios** — gera as visões consolidadas para a mesa de risco.

### 2.2 Stack tecnológica

A stack abaixo é a adotada no MVP. O princípio do polimorfismo no motor (§2.3) é preservado independentemente do framework.

- **Backend:** PHP 8.3+ com Laravel 13 (suporta PHP 8.3–8.5).
- **Banco de dados:** PostgreSQL 15+ (necessário para o índice único parcial de `posicao_movimentacao`, tipos `NUMERIC` exatos e `JSONB`).
- **ORM:** Eloquent, em arquitetura **MVC nativa com *fat model*** (ActiveRecord). O cálculo de MtM vive nos próprios Models Eloquent (§4); não há camada de domínio em PHP puro separada nem repositórios/contratos de persistência — os serviços de aplicação usam Eloquent diretamente. O polimorfismo do motor é preservado por uma fábrica de hidratação no próprio ORM (`newFromBuilder`, §4.5). Decisão de arquitetura: Alternativa A — *fat model* (cálculo nos Models, sem domínio puro separado), detalhada em §4 e §4.5.
- **Migrations:** Migrations do Laravel (Schema builder).
- **Frontend:** Blade + Livewire 4 (com Alpine.js para interações leves; Vite para empacotar CSS/JS e Tailwind; Flux UI via starter kit). Telas server-rendered, sem SPA.
- **Agendador:** Laravel Task Scheduler (uma entrada de cron chamando `php artisan schedule:run`) para disparar o processamento diário do motor.
- **Autenticação:** Laravel Sanctum — sessão para a interface web Livewire e tokens de acesso para a API REST (§5). Hashing de senha com bcrypt/argon2id. Ver §9.2.

### 2.3 Princípios de design

- **Polimorfismo sobre condicionais:** o motor MtM não contém `if/else` por tipo de instrumento. Cada classe filha implementa `calcularMtm($precoMercado)` à sua maneira.
- **Single Responsibility:** cada classe e cada serviço tem uma única razão para mudar.
- **Aberto/fechado:** adicionar um novo tipo de instrumento (swap, asiática, future spread) deve exigir somente uma nova classe — sem alterar o motor.
- **Idempotência do motor:** rodar duas vezes para a mesma data produz o mesmo resultado, não duplica registros.
- **Auditoria por design:** toda execução grava o preço de referência usado, a data, o usuário/processo que disparou e o resultado. Nada é sobrescrito silenciosamente.

---

## 3. Modelo de dados

### 3.1 Diagrama lógico

```
produto (1) ──< (N) preco_referencia
produto (1) ──< (N) posicao
posicao (1) ──< (N) mtm_diario
preco_referencia (1) ──< (N) mtm_diario
motor_execucao (1) ──< (N) mtm_diario

posicao (1) ──── (0..1) posicao_futuro
posicao (1) ──── (0..1) posicao_ndf
posicao (1) ──── (0..1) posicao_opcao
posicao_opcao (1) ──< (N) posicao_opcao_perna
posicao (1) ──── (0..1) posicao_otc
posicao (1) ──< (N) posicao_movimentacao   (no MVP, somente FUTURO)
```

Cada linha em `posicao` tem **exatamente um** registro em uma — e apenas uma — das tabelas filhas, identificado pelo campo `instrumento`.

### 3.2 Tabelas

#### 3.2.1 produto

Cadastro mestre de commodities.

| Coluna | Tipo | Restrições | Descrição |
|---|---|---|---|
| `id` | SERIAL | PK | Identificador único |
| `nome` | VARCHAR(60) | NOT NULL, UNIQUE | Nome do produto (ex.: "Soja CBOT", "Milho B3") |
| `unidade` | VARCHAR(20) | NOT NULL | Unidade de cotação (saca 60kg, bushel, tonelada, arroba) |
| `bolsa_ref` | VARCHAR(20) | NOT NULL | Bolsa de referência (CBOT, B3, ICE, NYMEX) |
| `moeda_cotacao` | VARCHAR(3) | NOT NULL | Moeda em que o produto é cotado (USD, BRL) |
| `ativo` | BOOLEAN | NOT NULL DEFAULT TRUE | Se o produto pode ser usado em novas posições |
| `criado_em` | TIMESTAMP | NOT NULL DEFAULT NOW() | Auditoria |

#### 3.2.2 preco_referencia

Preço de fechamento diário do produto.

| Coluna | Tipo | Restrições | Descrição |
|---|---|---|---|
| `id` | SERIAL | PK | Identificador único |
| `produto_id` | INTEGER | NOT NULL, FK → produto.id | Produto |
| `data_preco` | DATE | NOT NULL | Data de fechamento |
| `preco_fechamento` | NUMERIC(18, 6) | NOT NULL | Preço de fechamento na moeda do produto |
| `cambio_brl` | NUMERIC(18, 6) | NOT NULL | Taxa de câmbio para BRL no dia |
| `vol_implicita` | NUMERIC(8, 4) | NULL | Volatilidade implícita anualizada (reservado, não usado no MVP) |
| `taxa_juros` | NUMERIC(8, 4) | NULL | Taxa de juros livre de risco (reservado, não usado no MVP) |
| `criado_em` | TIMESTAMP | NOT NULL DEFAULT NOW() | Auditoria |

**Constraint:** UNIQUE (`produto_id`, `data_preco`) — uma única cotação por produto por dia.

#### 3.2.3 posicao

Tabela mãe com atributos comuns a todos os instrumentos.

| Coluna | Tipo | Restrições | Descrição |
|---|---|---|---|
| `id` | SERIAL | PK | Identificador único |
| `produto_id` | INTEGER | NOT NULL, FK → produto.id | Produto subjacente |
| `instrumento` | VARCHAR(10) | NOT NULL, CHECK IN ('FUTURO','NDF','OPCAO','OTC') | Tipo de instrumento |
| `mercado` | VARCHAR(10) | NOT NULL, CHECK IN ('BOLSA','BALCAO') | Bolsa ou balcão |
| `lado` | VARCHAR(10) | NOT NULL, CHECK IN ('COMPRADO','VENDIDO') | Direção da posição |
| `quantidade` | NUMERIC(18, 4) | NOT NULL CHECK (quantidade >= 0) | Quantidade atual do contrato. Para FUTURO é mantida igual à soma das movimentações (RN-024) e só chega a 0 por redução total, que encerra a posição (RN-022) |
| `data_entrada` | DATE | NOT NULL | Data de abertura |
| `data_vencimento` | DATE | NOT NULL | Data de vencimento |
| `contraparte` | VARCHAR(100) | NULL | Contraparte do OTC/NDF (NULL para BOLSA) |
| `status` | VARCHAR(15) | NOT NULL, CHECK IN ('ABERTA','ENCERRADA','VENCIDA') DEFAULT 'ABERTA' | Estado da posição |
| `observacoes` | TEXT | NULL | Notas livres |
| `criado_em` | TIMESTAMP | NOT NULL DEFAULT NOW() | Auditoria |
| `criado_por` | VARCHAR(60) | NOT NULL | Usuário que cadastrou |

> **Convenção para OPCAO:** quando `instrumento = 'OPCAO'`, quantidade e direção operacionais vivem nas pernas (ver §3.2.6a e RN-004c). Na posição mãe, convenciona-se `quantidade = 1` e `lado` meramente informativo (direção predominante da estratégia). Ver RN-004e.

> **Convenção para FUTURO (v1.3):** a quantidade é dinâmica. O cadastro da posição cria automaticamente a movimentação de `ABERTURA` e os aumentos/reduções posteriores são registrados em `posicao_movimentacao` (§3.2.4a). A coluna `quantidade` é atualizada transacionalmente a cada movimentação e deve satisfazer a invariante da RN-024.

#### 3.2.4 posicao_futuro

| Coluna | Tipo | Restrições | Descrição |
|---|---|---|---|
| `posicao_id` | INTEGER | PK, FK → posicao.id ON DELETE CASCADE | Referência à posição |
| `preco_entrada` | NUMERIC(18, 6) | NOT NULL | Preço da abertura (corresponde à movimentação `ABERTURA`). **Não** é o preço médio — o preço médio é derivado de `posicao_movimentacao` (ver §4.3.1 e RN-021) |
| `codigo_contrato` | VARCHAR(20) | NOT NULL | Código do contrato (ex.: "ZSU24" para soja CBOT setembro/24) |

#### 3.2.4a posicao_movimentacao

Histórico de movimentações (abertura, aumentos e reduções) de uma posição. No MVP, somente posições com `instrumento = 'FUTURO'` possuem movimentações (RN-020); a tabela referencia `posicao` (e não `posicao_futuro`) para que NDF/OTC possam aderir em iterações futuras sem mudança de esquema.

| Coluna | Tipo | Restrições | Descrição |
|---|---|---|---|
| `id` | SERIAL | PK | Identificador único |
| `posicao_id` | INTEGER | NOT NULL, FK → posicao.id ON DELETE CASCADE | Posição movimentada |
| `tipo` | VARCHAR(10) | NOT NULL, CHECK IN ('ABERTURA','AUMENTO','REDUCAO') | Natureza da movimentação |
| `data_movimentacao` | DATE | NOT NULL | Data da operação |
| `quantidade` | NUMERIC(18, 4) | NOT NULL CHECK (quantidade > 0) | Sempre positiva; o efeito (entra/sai) vem de `tipo` |
| `preco` | NUMERIC(18, 6) | NOT NULL CHECK (preco > 0) | Preço negociado na movimentação |
| `criado_em` | TIMESTAMP | NOT NULL DEFAULT NOW() | Auditoria |
| `criado_por` | VARCHAR(60) | NOT NULL | Usuário que registrou |

**Constraint:** `CREATE UNIQUE INDEX uq_mov_abertura ON posicao_movimentacao(posicao_id) WHERE tipo = 'ABERTURA';` — exatamente uma `ABERTURA` por posição, criada automaticamente no cadastro (RN-020).

> **Imutabilidade:** movimentações não são editadas nem removidas no MVP (RN-025) — princípio de auditoria por design (§2.3). A remoção ocorre apenas em cascata com a exclusão da posição (`DELETE /posicoes/{id}`, permitido somente sem MtM).

#### 3.2.5 posicao_ndf

| Coluna | Tipo | Restrições | Descrição |
|---|---|---|---|
| `posicao_id` | INTEGER | PK, FK → posicao.id ON DELETE CASCADE | Referência à posição |
| `taxa_contratada` | NUMERIC(18, 6) | NOT NULL | Taxa contratada no NDF |
| `valor_nocional` | NUMERIC(18, 2) | NOT NULL | Valor nocional do contrato |
| `moeda_nocional` | VARCHAR(3) | NOT NULL | Moeda do nocional |

#### 3.2.6 posicao_opcao

Cabeçalho da estrutura de opção. Uma estrutura pode conter uma ou mais pernas (legs), permitindo modelar estratégias como straddle, strangle, collar, bull/bear spread, butterfly etc.

| Coluna | Tipo | Restrições | Descrição |
|---|---|---|---|
| `posicao_id` | INTEGER | PK, FK → posicao.id ON DELETE CASCADE | Referência à posição |
| `nome_estrutura` | VARCHAR(60) | NULL | Nome livre da estratégia (ex.: "Straddle", "Collar", "Bull Call Spread") |

#### 3.2.6a posicao_opcao_perna

Cada linha representa uma perna individual da estrutura de opção. Uma estrutura com apenas uma perna é um caso particular válido.

| Coluna | Tipo | Restrições | Descrição |
|---|---|---|---|
| `id` | SERIAL | PK | Identificador único |
| `posicao_id` | INTEGER | NOT NULL, FK → posicao_opcao.posicao_id ON DELETE CASCADE | Estrutura a que esta perna pertence |
| `sequencia` | SMALLINT | NOT NULL CHECK (sequencia > 0) | Ordem da perna dentro da estrutura (1, 2, 3…) |
| `tipo_opcao` | VARCHAR(4) | NOT NULL, CHECK IN ('CALL','PUT') | Tipo da opção desta perna |
| `estilo` | VARCHAR(10) | NOT NULL, CHECK IN ('EUROPEIA','AMERICANA') | Estilo de exercício desta perna |
| `strike` | NUMERIC(18, 6) | NOT NULL CHECK (strike > 0) | Preço de exercício |
| `premio_pago` | NUMERIC(18, 6) | NOT NULL CHECK (premio_pago >= 0) | Prêmio unitário (magnitude, sempre ≥ 0); a direção é dada pelo campo `lado` da perna |
| `quantidade` | NUMERIC(18, 4) | NOT NULL CHECK (quantidade > 0) | Quantidade de contratos desta perna |
| `lado` | VARCHAR(10) | NOT NULL, CHECK IN ('COMPRADO','VENDIDO') | Direção desta perna, independente do lado da posição mãe |

**Constraint:** UNIQUE (`posicao_id`, `sequencia`) — cada perna tem sequência única dentro da estrutura.

> **Nota:** no MVP todas as pernas são precificadas por valor intrínseco. Black-76, gregas e valor temporal ficam para iterações futuras.

#### 3.2.7 posicao_otc

| Coluna | Tipo | Restrições | Descrição |
|---|---|---|---|
| `posicao_id` | INTEGER | PK, FK → posicao.id ON DELETE CASCADE | Referência à posição |
| `preco_entrada` | NUMERIC(18, 6) | NOT NULL | Preço de entrada negociado |
| `indexador` | VARCHAR(30) | NOT NULL | Índice de referência (ex.: "CEPEA_SOJA", "CBOT_SOJA") |
| `premio_otc` | NUMERIC(18, 6) | NOT NULL DEFAULT 0 | Prêmio sobre o indexador (pode ser negativo) |

#### 3.2.8 mtm_diario

Histórico de marcação a mercado por posição por dia.

| Coluna | Tipo | Restrições | Descrição |
|---|---|---|---|
| `id` | SERIAL | PK | Identificador único |
| `posicao_id` | INTEGER | NOT NULL, FK → posicao.id | Posição marcada |
| `preco_ref_id` | INTEGER | NOT NULL, FK → preco_referencia.id | Preço usado no cálculo |
| `data_calculo` | DATE | NOT NULL | Data do cálculo |
| `preco_mercado` | NUMERIC(18, 6) | NOT NULL | Preço aplicado |
| `mtm_valor` | NUMERIC(18, 2) | NOT NULL | Valor MtM no dia (em BRL) |
| `variacao_dia` | NUMERIC(18, 2) | NOT NULL | MtM hoje − MtM ontem. Como a comparação é feita em BRL, o valor mistura variação de preço e de câmbio entre os dias — comportamento aceito no MVP; a decomposição preço × câmbio fica para a Fase 2. Em dia de movimentação (§3.2.4a), a variação também embute o efeito da mudança de quantidade e de preço médio — igualmente aceito no MVP |
| `pl_acumulado` | NUMERIC(18, 2) | NOT NULL | P&L acumulado desde a abertura: `mtm_valor` + P&L realizado acumulado das reduções até a data (RN-023). Para posições sem reduções — e para NDF/OPCAO/OTC no MVP — coincide com `mtm_valor` |
| `execucao_id` | INTEGER | NULL, FK → motor_execucao.id | Execução do motor que gerou/atualizou o registro |
| `processado_em` | TIMESTAMP | NOT NULL DEFAULT NOW() | Quando o cálculo rodou |

**Constraint:** UNIQUE (`posicao_id`, `data_calculo`) — uma única marcação por posição por dia. Reexecutar o motor para a mesma data faz UPDATE, não INSERT.

#### 3.2.9 motor_execucao

Registro de cada execução do motor MtM, atendendo ao princípio de auditoria por design (§2.3) e dando suporte aos endpoints `GET /motor/execucoes` (§5.2.4).

| Coluna | Tipo | Restrições | Descrição |
|---|---|---|---|
| `id` | SERIAL | PK | Identificador único (retornado como `execucao_id` na API) |
| `data_calculo` | DATE | NOT NULL | Data de referência do processamento |
| `disparado_por` | VARCHAR(60) | NOT NULL | Usuário ou processo (ex.: "agendador") que disparou |
| `iniciado_em` | TIMESTAMP | NOT NULL DEFAULT NOW() | Início da execução |
| `finalizado_em` | TIMESTAMP | NULL | Fim da execução (NULL enquanto em andamento) |
| `total_posicoes` | INTEGER | NULL | Total de posições processadas |
| `sucessos` | INTEGER | NULL | Quantidade de posições calculadas com sucesso |
| `falhas` | JSONB | NULL | Lista de falhas: `[{ "posicao_id": …, "motivo": … }]` |

#### 3.2.10 usuario

| Coluna | Tipo | Restrições | Descrição |
|---|---|---|---|
| `id` | SERIAL | PK | Identificador único |
| `login` | VARCHAR(60) | NOT NULL UNIQUE | Login |
| `nome` | VARCHAR(120) | NOT NULL | Nome completo |
| `senha_hash` | VARCHAR(255) | NOT NULL | Hash da senha (bcrypt/argon2) |
| `perfil` | VARCHAR(20) | NOT NULL CHECK IN ('OPERADOR','GESTOR','ADMIN') | Perfil de acesso |
| `ativo` | BOOLEAN | NOT NULL DEFAULT TRUE | Se pode fazer login |
| `criado_em` | TIMESTAMP | NOT NULL DEFAULT NOW() | Auditoria |

### 3.3 Índices recomendados

```sql
CREATE INDEX idx_preco_produto_data ON preco_referencia(produto_id, data_preco DESC);
CREATE INDEX idx_posicao_status ON posicao(status) WHERE status = 'ABERTA';
CREATE INDEX idx_posicao_produto ON posicao(produto_id);
CREATE INDEX idx_mtm_posicao_data ON mtm_diario(posicao_id, data_calculo DESC);
CREATE INDEX idx_mtm_data ON mtm_diario(data_calculo);
CREATE INDEX idx_mov_posicao_data ON posicao_movimentacao(posicao_id, data_movimentacao, id);
```

---

## 4. Hierarquia de classes (Models Eloquent — *fat model*)

> **Arquitetura MVC nativa / *fat model*:** as classes abaixo são **Models Eloquent**
> (`app/Models/`) que concentram tanto a persistência quanto o cálculo de MtM — não há
> domínio em PHP puro separado. O código a seguir mostra a **lógica de cálculo** (as
> fórmulas, idênticas às validadas); na implementação esses métodos vivem nos Models
> (`Posicao` base + subclasses `Futuro`/`Ndf`/`Opcao`/`Otc`, `Perna`, `Movimentacao`).
> **Salvaguarda de testabilidade e pureza:** os métodos de cálculo (`calcularMtm`,
> `plRealizado`, `sinal`, `precoMedio`, `quantidadeAtual`, `replay`) operam **somente**
> sobre atributos/relações já carregados (eager loading garantido pelo serviço) — não
> montam query nem tocam no banco; a aritmética é extraível para *traits* puros em
> `app/Models/Concerns/`. O polimorfismo do motor é preservado pela fábrica de
> hidratação `newFromBuilder` (§4.5), **sem** `if/switch` por tipo no motor
> (Alternativa A — *fat model*).

### 4.1 Diagrama UML conceitual

```
                    Posicao (abstrata)
                    + produto, lado, quantidade
                    + dataEntrada, dataVencimento
                    + plRealizado() → 0.0 (padrão; Futuro sobrescreve)
                    + calcularMtm(precoMercado): abstract
                            △
        ┌───────────┬───────┴───────┬────────────────┐
        │           │               │                │
     Futuro          NDF             Opcao            OTC
   + precoEntrada   + taxaContr.     + nomeEstrutura  + indexador
   + codigoContr.   + valorNocional  + pernas[]       + premioOtc
   + movimentacoes[]                                  + precoEntrada
   + precoMedio()
   + quantidadeAtual()
   + plRealizado()
   + calcularMtm()  + calcularMtm()  + calcularMtm()  + calcularMtm()
                                        ◆
                                        │ 1..N (composição)
                                      Perna
                                   + tipoOpcao (CALL/PUT)
                                   + estilo, strike
                                   + premioPago, quantidade
                                   + lado
                                   + calcularMtm()

        ◆
        │ 1..N (composição)
     Movimentacao
   + tipo (ABERTURA/AUMENTO/REDUCAO)
   + dataMovimentacao
   + quantidade, preco
```

### 4.2 Model base `Posicao`

> No *fat model*, `Posicao` é o Model Eloquent base (`extends Illuminate\Database\Eloquent\Model`);
> as subclasses estendem-no e a hidratação polimórfica fica no `newFromBuilder` (§4.5).
> O trecho abaixo destaca o **contrato de cálculo** (atributos vêm de colunas/relações
> carregadas); as fórmulas são idênticas às já validadas.

```php
<?php

namespace App\Models;

abstract class Posicao // Model Eloquent base (fat model); ver nota acima
{
    public function __construct(
        public readonly int $id,
        public readonly int $produtoId,
        public readonly string $lado,            // "COMPRADO" | "VENDIDO"
        public readonly float $quantidade,
        public readonly \DateTimeImmutable $dataEntrada,
        public readonly \DateTimeImmutable $dataVencimento,
        public readonly string $status,
    ) {}

    public function sinal(): int
    {
        return $this->lado === 'COMPRADO' ? 1 : -1;
    }

    /**
     * P&L já realizado em moeda original. Subclasses com movimentações
     * (Futuro) sobrescrevem; os demais instrumentos retornam 0.
     */
    public function plRealizado(): float
    {
        return 0.0;
    }

    /** Calcula o valor MtM em moeda original. A conversão p/ BRL é externa. */
    abstract public function calcularMtm(float $precoMercado): float;
}
```

### 4.3 Subclasses concretas

#### 4.3.1 Futuro

```php
final class Movimentacao
{
    public function __construct(
        public readonly string $tipo,                        // "ABERTURA" | "AUMENTO" | "REDUCAO"
        public readonly \DateTimeImmutable $dataMovimentacao,
        public readonly float $quantidade,                   // sempre > 0; o efeito (entra/sai) vem de $tipo
        public readonly float $preco,
    ) {}
}

final class Futuro extends Posicao
{
    /** @param Movimentacao[] $movimentacoes */
    public function __construct(
        int $id,
        int $produtoId,
        string $lado,
        float $quantidade,
        \DateTimeImmutable $dataEntrada,
        \DateTimeImmutable $dataVencimento,
        string $status,
        public readonly float $precoEntrada,     // preço da ABERTURA (imutável); ver RN-021
        public readonly string $codigoContrato,
        public readonly array $movimentacoes = [],
    ) {
        parent::__construct($id, $produtoId, $lado, $quantidade,
            $dataEntrada, $dataVencimento, $status);
    }

    /**
     * Reaplica as movimentações em ordem cronológica.
     * @return array{0: float, 1: float, 2: float} [quantidadeAtual, precoMedio, plRealizado]
     */
    private function replay(): array
    {
        $movs = $this->movimentacoes;
        usort($movs, fn (Movimentacao $a, Movimentacao $b) =>
            [$a->dataMovimentacao, $a->tipo !== 'ABERTURA']   // ABERTURA primeiro no empate de data
            <=> [$b->dataMovimentacao, $b->tipo !== 'ABERTURA']);

        $qtd = 0.0; $pm = 0.0; $realizado = 0.0;
        foreach ($movs as $mov) {
            if ($mov->tipo === 'ABERTURA' || $mov->tipo === 'AUMENTO') {
                $pm = ($qtd * $pm + $mov->quantidade * $mov->preco) / ($qtd + $mov->quantidade);
                $qtd += $mov->quantidade;
            } else { // REDUCAO — o preço médio não muda (RN-021)
                $realizado += ($mov->preco - $pm) * $mov->quantidade * $this->sinal();
                $qtd -= $mov->quantidade;
            }
        }
        return [$qtd, $pm, $realizado];
    }

    public function precoMedio(): float
    {
        return $this->movimentacoes ? $this->replay()[1] : $this->precoEntrada;
    }

    public function quantidadeAtual(): float
    {
        return $this->movimentacoes ? $this->replay()[0] : $this->quantidade;
    }

    public function plRealizado(): float
    {
        return $this->movimentacoes ? $this->replay()[2] : 0.0;
    }

    public function calcularMtm(float $precoMercado): float
    {
        return ($precoMercado - $this->precoMedio()) * $this->quantidadeAtual() * $this->sinal();
    }
}
```

**Lógica:** o preço médio é a média ponderada das entradas — a cada `ABERTURA`/`AUMENTO`, `pm' = (qtd × pm + qtd_mov × preco_mov) / (qtd + qtd_mov)`. Reduções **não** alteram o preço médio: realizam `(preco_reducao − pm) × qtd_reduzida × sinal` e diminuem a quantidade. O MtM (P&L não realizado) usa o preço médio e a quantidade atual: `(preco_mercado − preco_medio) × quantidade_atual × sinal`. O campo `preco_entrada` permanece como o preço da abertura e **não** é reaproveitado como preço médio (RN-021).

**Exemplo:** abre 100 @ 1400 e aumenta 50 @ 1430 → preço médio = (100×1400 + 50×1430) / 150 = 1410. Reduz 50 @ 1440 → realizado = (1440 − 1410) × 50 = 1.500; restam 100 contratos com preço médio 1410. Com mercado a 1450, MtM = (1450 − 1410) × 100 = 4.000.

#### 4.3.2 NDF

```php
final class NDF extends Posicao
{
    public function __construct(
        int $id, int $produtoId, string $lado, float $quantidade,
        \DateTimeImmutable $dataEntrada, \DateTimeImmutable $dataVencimento, string $status,
        public readonly float $taxaContratada,
        public readonly float $valorNocional,
    ) {
        parent::__construct($id, $produtoId, $lado, $quantidade,
            $dataEntrada, $dataVencimento, $status);
    }

    public function calcularMtm(float $precoMercado): float
    {
        return ($precoMercado - $this->taxaContratada) * $this->valorNocional * $this->sinal();
    }
}
```

**Lógica:** diferença entre a taxa de mercado atual e a taxa contratada, multiplicada pelo valor nocional. No MVP usa-se a taxa spot como aproximação da forward (sem ajuste por taxa de juros).

> **Convenção cambial:** para NDF cambial (ex.: nocional em USD com taxa em BRL/USD), o resultado de `(taxa_mercado − taxa_contratada) × nocional` **já está em BRL**. Para evitar dupla conversão no motor (que multiplica todo MtM por `cambio_brl`, ver §4.4), a moeda é cadastrada como `produto` (ex.: "Dólar USD/BRL") com `moeda_cotacao = 'BRL'` e `cambio_brl = 1`, sendo `preco_fechamento` a própria taxa de câmbio do dia. Assim a multiplicação do motor é neutra. Ver premissas §1.4 e RN-015.

#### 4.3.3 Opção

Uma estrutura de opção é composta por uma ou mais pernas. Cada perna tem seu próprio tipo (CALL/PUT), strike, prêmio, quantidade e direção. O MtM da estrutura é a soma do MtM de cada perna, permitindo modelar naturalmente qualquer estratégia multi-leg.

```php
final class Perna
{
    public function __construct(
        public readonly string $tipoOpcao,   // "CALL" | "PUT"
        public readonly string $estilo,
        public readonly float $strike,
        public readonly float $premioPago,
        public readonly float $quantidade,
        public readonly string $lado,        // "COMPRADO" | "VENDIDO"
    ) {}

    public function sinal(): int
    {
        return $this->lado === 'COMPRADO' ? 1 : -1;
    }

    public function calcularMtm(float $precoMercado): float
    {
        $valorIntrinseco = $this->tipoOpcao === 'CALL'
            ? max($precoMercado - $this->strike, 0)
            : max($this->strike - $precoMercado, 0);
        return ($valorIntrinseco - $this->premioPago) * $this->quantidade * $this->sinal();
    }
}

final class Opcao extends Posicao
{
    /** @param Perna[] $pernas */
    public function __construct(
        int $id, int $produtoId, string $lado, float $quantidade,
        \DateTimeImmutable $dataEntrada, \DateTimeImmutable $dataVencimento, string $status,
        public readonly ?string $nomeEstrutura,
        public readonly array $pernas,
    ) {
        parent::__construct($id, $produtoId, $lado, $quantidade,
            $dataEntrada, $dataVencimento, $status);
    }

    public function calcularMtm(float $precoMercado): float
    {
        return array_sum(array_map(
            fn (Perna $perna) => $perna->calcularMtm($precoMercado),
            $this->pernas,
        ));
    }
}
```

**Lógica:** cada perna calcula seu valor intrínseco menos o prêmio pago, multiplicado pela quantidade e pelo sinal (comprado/vendido) da própria perna. O MtM da estrutura é a soma das pernas. Não considera valor temporal nem volatilidade. Exemplos de estruturas suportadas:

| Estrutura | Pernas |
|---|---|
| Opção simples | 1 perna (CALL ou PUT) |
| Straddle | 1 CALL comprada + 1 PUT comprada, mesmo strike |
| Strangle | 1 CALL comprada + 1 PUT comprada, strikes diferentes |
| Collar | 1 PUT comprada + 1 CALL vendida |
| Bull Call Spread | 1 CALL comprada (strike baixo) + 1 CALL vendida (strike alto) |
| Bear Put Spread | 1 PUT comprada (strike alto) + 1 PUT vendida (strike baixo) |
| Butterfly | 3 pernas com proporções 1:−2:1 |

#### 4.3.4 OTC

```php
final class OTC extends Posicao
{
    public function __construct(
        int $id, int $produtoId, string $lado, float $quantidade,
        \DateTimeImmutable $dataEntrada, \DateTimeImmutable $dataVencimento, string $status,
        public readonly float $precoEntrada,
        public readonly string $indexador,
        public readonly float $premioOtc,
    ) {
        parent::__construct($id, $produtoId, $lado, $quantidade,
            $dataEntrada, $dataVencimento, $status);
    }

    public function calcularMtm(float $precoMercado): float
    {
        $precoEfetivo = $precoMercado + $this->premioOtc;
        return ($precoEfetivo - $this->precoEntrada) * $this->quantidade * $this->sinal();
    }
}
```

**Lógica:** preço do indexador no dia + prêmio OTC = preço efetivo. MtM é a diferença entre esse preço efetivo e o preço de entrada.

### 4.4 Motor MtM

> No *fat model* o `MotorMtm` vive em `app/Services/MotorMtm.php` e itera **Models
> Eloquent** diretamente (sem repositórios/contratos — DM-2). A query traz as posições
> abertas com eager loading; `newFromBuilder` (§4.5) já devolve a subclasse correta, de
> modo que `calcularMtm()` é polimórfico. A idempotência (RN-013) é o
> `MtmDiario::updateOrCreate(...)`. A orquestração/auditoria (`motor_execucao`) e o
> upsert vivem no `ServicoMotor`; o trecho abaixo destaca o laço de cálculo.

```php
namespace App\Services;

use App\Models\{Posicao, PrecoReferencia, MtmDiario};

class MotorMtm
{
    public function processarDia(\DateTimeImmutable $dataCalculo): ResultadoProcessamento
    {
        $resultado = new ResultadoProcessamento($dataCalculo);

        // Eager loading evita N+1; newFromBuilder (§4.5) hidrata a subclasse correta.
        $posicoes = Posicao::query()
            ->with(['futuro.movimentacoes', 'ndf', 'opcao.pernas', 'otc'])
            ->where('status', 'ABERTA')
            ->get();

        foreach ($posicoes as $posicao) {
            try {
                $preco = PrecoReferencia::query()
                    ->where('produto_id', $posicao->produto_id)
                    ->where('data_preco', $dataCalculo->format('Y-m-d'))
                    ->first();
                if ($preco === null) {
                    $resultado->falhas[] = [$posicao->id, 'Preço não cadastrado para a data'];
                    continue;
                }

                $mtmMoedaOrig = $posicao->calcularMtm((float) $preco->preco_fechamento);
                $mtmBrl = $mtmMoedaOrig * (float) $preco->cambio_brl;

                $mtmOntem = MtmDiario::query()
                    ->where('posicao_id', $posicao->id)
                    ->where('data_calculo', '<', $dataCalculo->format('Y-m-d'))
                    ->orderByDesc('data_calculo')
                    ->first();
                $mtmOntemValor = (float) ($mtmOntem?->mtm_valor ?? 0.0);
                $variacao = $mtmBrl - $mtmOntemValor;

                // P&L acumulado = MtM (não realizado) + P&L realizado acumulado das
                // reduções (RN-023), convertido pelo câmbio do dia. Sem movimentações,
                // plRealizado() = 0 e o valor coincide com $mtmBrl (ver §3.2.8).
                $plAcumulado = $mtmBrl + $posicao->plRealizado() * (float) $preco->cambio_brl;

                // Idempotência (RN-013): UPSERT por (posicao_id, data_calculo).
                MtmDiario::updateOrCreate(
                    ['posicao_id' => $posicao->id, 'data_calculo' => $dataCalculo->format('Y-m-d')],
                    [
                        'preco_ref_id'  => $preco->id,
                        'preco_mercado' => $preco->preco_fechamento,
                        'mtm_valor'     => $mtmBrl,
                        'variacao_dia'  => $variacao,
                        'pl_acumulado'  => $plAcumulado,
                    ],
                );
                $resultado->sucessos[] = $posicao->id;
            } catch (\Throwable $e) {
                $resultado->falhas[] = [$posicao->id, $e->getMessage()];
            }
        }

        return $resultado;
    }
}
```

**Observe:** o motor não tem nenhum `if` por tipo de instrumento. Para adicionar um novo tipo (swap, asiática) basta criar um Model que estende `Posicao`, implementa `calcularMtm` e ganha um `case` no `match` do `newFromBuilder` (§4.5). O motor não muda.

> **Nota sobre `pl_acumulado`:** o P&L acumulado soma ao MtM (P&L não realizado) o P&L realizado acumulado das reduções de futuros (`$posicao->plRealizado()`, RN-023), convertido pelo câmbio do dia. Para instrumentos sem movimentações o realizado é 0 e `pl_acumulado` coincide com `$mtmBrl`. O motor continua sem nenhum `if` por tipo: `plRealizado()` é um método polimórfico definido na classe base (§4.2) e sobrescrito por `Futuro` (§4.3.1).

> **Nota sobre conversão cambial:** a linha `$mtmBrl = $mtmMoedaOrig * $preco->cambioBrl` pressupõe que `calcularMtm` retorna valor na moeda de cotação do produto. Para NDF cambial, a convenção de cadastro com `cambio_brl = 1` (§4.3.2) mantém essa linha correta sem caso especial.

### 4.5 Fábrica de hidratação polimórfica (`newFromBuilder`)

No *fat model* não há repositório nem tradução ORM⇄domínio: o objeto que o motor usa
**é** o Model. Para que `Posicao::query()->get()` devolva a **subclasse certa** (e
`calcularMtm()` seja polimórfico sem `if` no motor), o Model base sobrescreve a
hidratação por `instrumento` — réplica do antigo `match` da factory, agora **dentro**
do Eloquent:

```php
namespace App\Models;

class Posicao extends \Illuminate\Database\Eloquent\Model
{
    protected $table = 'posicao';

    // Único ponto onde o tipo importa (factory). Depois disso, polimorfismo puro.
    public function newFromBuilder($attributes = [], $connection = null)
    {
        $classe = match ($attributes->instrumento ?? null) {
            'FUTURO' => Futuro::class,
            'NDF'    => Ndf::class,
            'OPCAO'  => Opcao::class,
            'OTC'    => Otc::class,
            default  => static::class,
        };

        $model = (new $classe)->newInstance([], true);
        $model->setRawAttributes((array) $attributes, true);
        $model->setConnection($connection ?: $this->getConnectionName());

        return $model;
    }
}
```

- Cada subclasse (`Futuro extends Posicao`, etc.) aponta `$table = 'posicao'` e acessa
  a tabela-filha por relação (`hasOne` — `futuro()`, `ndf()`, `opcao()`, `otc()`) com
  eager loading; `Movimentacao` e `Perna` são Models filhos. Os métodos de cálculo do
  §4.3 (`calcularMtm`, `precoMedio`, `replay`, …) vivem nessas subclasses e operam só
  sobre relações já carregadas (§4, nota de salvaguarda).
- **Aberto/fechado preservado:** novo instrumento = novo Model `extends Posicao` + um
  `case` neste `match`. Motor e serviços não mudam.

O único ponto onde o tipo importa é o `match` da hidratação. Depois disso, polimorfismo puro.

---

## 5. APIs (REST)

### 5.1 Convenções

- Base path: `/api/v1`
- Autenticação: header `Authorization: Bearer <token>` (Laravel Sanctum)
- Formato: JSON com `Content-Type: application/json`
- Datas: ISO 8601 (`YYYY-MM-DD`)
- Decimais: número sem aspas
- Erros: estrutura `{ "erro": "código", "mensagem": "descrição" }` com status HTTP apropriado

### 5.2 Endpoints

#### 5.2.1 Produtos

```
GET    /produtos              Lista produtos
GET    /produtos/{id}         Detalhe
POST   /produtos              Cria
PUT    /produtos/{id}         Atualiza
DELETE /produtos/{id}         Inativa (soft delete via campo `ativo`)
```

#### 5.2.2 Preços de referência

```
GET    /precos?produto_id=X&data_inicio=Y&data_fim=Z   Lista
POST   /precos                                          Cadastra (1 preço)
POST   /precos/upload                                   Upload CSV em lote
DELETE /precos/{id}                                     Remove
```

> **Restrição:** um preço já referenciado por `mtm_diario` (FK `preco_ref_id`) não pode ser removido — o endpoint retorna `409 Conflict`. Ver RN-010a.

**Formato do CSV:**

```csv
produto_id,data_preco,preco_fechamento,cambio_brl
1,2026-05-23,1450.50,5.12
2,2026-05-23,72.30,5.12
```

#### 5.2.3 Posições

```
GET    /posicoes?status=ABERTA&produto_id=X   Lista
GET    /posicoes/{id}                          Detalhe (com dados do tipo)
POST   /posicoes/futuro                        Cria posição futuro
POST   /posicoes/ndf                           Cria posição NDF
POST   /posicoes/opcao                         Cria posição opção
POST   /posicoes/otc                           Cria posição OTC
POST   /posicoes/{id}/encerrar                 Encerra posição (ação, não substituição de recurso)
DELETE /posicoes/{id}                          Remove (somente se sem MtM)
GET    /posicoes/{id}/movimentacoes            Lista movimentações (somente FUTURO)
POST   /posicoes/{id}/movimentacoes            Registra AUMENTO ou REDUCAO (somente FUTURO)
```

**Exemplo — payload de criação de futuro:**

```json
{
  "produto_id": 1,
  "lado": "COMPRADO",
  "quantidade": 100,
  "data_entrada": "2026-05-23",
  "data_vencimento": "2026-09-15",
  "preco_entrada": 1420.00,
  "codigo_contrato": "ZSU24",
  "observacoes": "Hedge da safra 24/25"
}
```

> `POST /posicoes/futuro` cria automaticamente a movimentação `ABERTURA` com a `quantidade` e o `preco_entrada` do payload e `data_movimentacao = data_entrada` (RN-020). Aumentos e reduções posteriores usam `POST /posicoes/{id}/movimentacoes`.

**Exemplo — registrar movimentação (`POST /posicoes/1001/movimentacoes`):**

```json
{
  "tipo": "AUMENTO",
  "data_movimentacao": "2026-06-01",
  "quantidade": 50,
  "preco": 1430.00
}
```

**Resposta (200) — estado recalculado da posição:**

```json
{
  "posicao_id": 1001,
  "movimentacao_id": 7,
  "quantidade_atual": 150,
  "preco_medio": 1410.00,
  "pl_realizado": 0.00,
  "status": "ABERTA"
}
```

Erros: `404` se a posição não existe; `409 Conflict` se `instrumento != 'FUTURO'` ou `status != 'ABERTA'`; `422` se `tipo = 'REDUCAO'` com `quantidade` maior que a quantidade atual (RN-022), se `quantidade <= 0` ou `preco <= 0`, ou se `data_movimentacao < data_entrada` (RN-025). Uma redução que zera a quantidade encerra a posição e a resposta traz `"status": "ENCERRADA"`.

**Exemplo — payload de criação de opção simples (1 perna):**

```json
{
  "produto_id": 1,
  "lado": "COMPRADO",
  "quantidade": 1,
  "data_entrada": "2026-05-23",
  "data_vencimento": "2026-09-15",
  "nome_estrutura": "Call simples",
  "pernas": [
    {
      "tipo_opcao": "CALL",
      "estilo": "EUROPEIA",
      "strike": 1450.00,
      "premio_pago": 30.00,
      "quantidade": 100,
      "lado": "COMPRADO"
    }
  ]
}
```

**Exemplo — payload de criação de estrutura multi-perna (Straddle):**

```json
{
  "produto_id": 1,
  "lado": "COMPRADO",
  "quantidade": 1,
  "data_entrada": "2026-05-23",
  "data_vencimento": "2026-09-15",
  "nome_estrutura": "Straddle",
  "pernas": [
    {
      "tipo_opcao": "CALL",
      "estilo": "EUROPEIA",
      "strike": 1450.00,
      "premio_pago": 30.00,
      "quantidade": 100,
      "lado": "COMPRADO"
    },
    {
      "tipo_opcao": "PUT",
      "estilo": "EUROPEIA",
      "strike": 1450.00,
      "premio_pago": 28.00,
      "quantidade": 100,
      "lado": "COMPRADO"
    }
  ],
  "observacoes": "Proteção contra movimento bidirecional"
}
```

#### 5.2.4 Motor MtM

```
POST /motor/processar           Dispara processamento para uma data
GET  /motor/execucoes           Histórico de execuções
GET  /motor/execucoes/{id}      Detalhe de execução (sucessos, falhas)
```

> As execuções são persistidas na tabela `motor_execucao` (§3.2.9); `execucao_id` na resposta corresponde a `motor_execucao.id`.

**Payload de disparo:**

```json
{ "data_calculo": "2026-05-23" }
```

**Resposta:**

```json
{
  "execucao_id": 47,
  "data_calculo": "2026-05-23",
  "posicoes_processadas": 23,
  "sucessos": 22,
  "falhas": [
    { "posicao_id": 18, "motivo": "Preço não cadastrado para a data" }
  ]
}
```

#### 5.2.5 Relatórios

```
GET /relatorios/posicao-aberta?data=YYYY-MM-DD
GET /relatorios/pl-diario?data=YYYY-MM-DD
GET /relatorios/exposicao-liquida?data=YYYY-MM-DD
GET /relatorios/historico-mtm?posicao_id=X
```

Todos os relatórios aceitam o parâmetro opcional `formato=json|csv|pdf`.

---

## 6. Telas e fluxos de usuário

### 6.1 Inventário de telas

1. **Login** — autenticação por usuário e senha.
2. **Dashboard** — visão geral do dia: P&L total, número de posições, status da última execução do motor.
3. **Cadastro de produtos** — CRUD simples com listagem em tabela.
4. **Lançamento de preços** — formulário para entrada manual e área de upload CSV.
5. **Cadastro de posições** — formulário dinâmico que se adapta ao tipo de instrumento selecionado.
6. **Listagem de posições** — tabela com filtros por status, produto e tipo. O detalhe de uma posição FUTURO exibe preço médio, quantidade atual, P&L realizado e o histórico de movimentações, com a ação **Movimentar** (aumento/redução).
7. **Execução do motor** — botão para disparar processamento de uma data + histórico de execuções.
8. **Relatório de posição aberta** — tabela consolidada com MtM atual; para posições FUTURO, exibe o preço médio.
9. **Relatório de P&L** — gráfico de evolução + tabela detalhada por posição.
10. **Relatório de exposição líquida** — agrupado por produto, mostrando comprado vs. vendido.

### 6.2 Fluxo principal — ciclo diário

```
Operador faz login
    ↓
Lança preços de fechamento do dia (manual ou CSV)
    ↓
Cadastra novas posições (se houver)
    ↓
Registra movimentações de futuros — aumentos/reduções (se houver)
    ↓
Dispara processamento do motor para a data
    ↓
Aguarda confirmação (sucessos/falhas)
    ↓
Consulta relatórios:
    - Posição aberta (snapshot do dia)
    - P&L diário e acumulado
    - Exposição líquida por produto
```

### 6.3 Wireframe textual — cadastro de posição

```
┌──────────────────────────────────────────────┐
│  Nova posição                                │
├──────────────────────────────────────────────┤
│  Tipo:        [▾ FUTURO | NDF | OPCAO | OTC] │
│  Produto:     [▾ Selecione...              ] │
│  Lado:        ( ) Comprado  ( ) Vendido      │
│  Quantidade:  [____________]                 │
│  Data entrada: [__/__/____]                  │
│  Vencimento:   [__/__/____]                  │
│                                              │
│  ─── Campos do tipo selecionado ───          │
│  [campos dinâmicos conforme o tipo]          │
│                                              │
│  (Para OPCAO) Nome da estrutura:             │
│  [____________]                              │
│  Pernas:                                     │
│  ┌──────────────────────────────────────┐   │
│  │ # │Tipo│Strike │Prêmio │Qtd│Lado    │   │
│  │ 1 │CALL│1450.00│ 30.00 │100│COMPRADO│   │
│  │ 2 │PUT │1450.00│ 28.00 │100│COMPRADO│   │
│  └──────────────────────────────────────┘   │
│  [+ Adicionar perna]  [− Remover última]     │
│                                              │
│  Contraparte: [____________] (BALCAO apenas) │
│  Observações: [__________________________]   │
│                                              │
│        [Cancelar]      [Salvar posição]      │
└──────────────────────────────────────────────┘
```

### 6.4 Wireframe textual — movimentar posição (FUTURO)

```
┌──────────────────────────────────────────────┐
│  Movimentar posição #1001 · ZSU24 (FUTURO)   │
├──────────────────────────────────────────────┤
│  Tipo:        ( ) Aumento   ( ) Redução      │
│  Data:        [__/__/____]                   │
│  Quantidade:  [____________]                 │
│  Preço:       [____________]                 │
│                                              │
│  ─── Prévia ───                              │
│  Qtd. atual:    50  →  100                   │
│  Preço médio:   1408,20  →  1418,65          │
│  P&L realizado desta operação:  —            │
│                                              │
│  ⚠ Redução total encerra a posição (RN-022)  │
│                                              │
│        [Cancelar]      [Confirmar]           │
└──────────────────────────────────────────────┘
```

A prévia é recalculada ao vivo: aumentos deslocam o preço médio (média ponderada, RN-021); reduções mantêm o preço médio e exibem o P&L realizado da operação (RN-023). Uma redução igual à quantidade atual encerra a posição (RN-022).

---

## 7. Regras de negócio

### 7.1 Cadastro de posições

- **RN-001:** no cadastro, a quantidade deve ser maior que zero. Lado define o sinal no cálculo. A quantidade só pode chegar a zero por redução total de um futuro (RN-022).
- **RN-002:** data de vencimento deve ser posterior à data de entrada.
- **RN-003:** posição em mercado BOLSA não exige contraparte; posição em mercado BALCAO exige.
- **RN-004:** cada perna de opção deve ter strike maior que zero e prêmio maior ou igual a zero.
- **RN-004a:** uma estrutura de opção deve conter pelo menos uma perna.
- **RN-004b:** não há limite máximo de pernas por estrutura; o sistema deve suportar ao menos 4 (butterfly, condor).
- **RN-004c:** cada perna tem sua própria quantidade e direção (COMPRADO/VENDIDO), independente do lado informado na posição mãe.
- **RN-004d:** pernas de uma mesma estrutura podem ter strikes, prêmios e quantidades diferentes entre si.
- **RN-004e:** para posições do tipo OPCAO, a posição mãe é cadastrada com `quantidade = 1` e o campo `lado` é meramente informativo (direção predominante da estratégia); o cálculo de MtM usa exclusivamente a quantidade e o lado de cada perna.
- **RN-005:** NDF deve ter valor nocional maior que zero.
- **RN-006:** o `indexador` da posição OTC deve corresponder a um produto cadastrado no sistema.

### 7.1a Movimentações de posição (FUTURO)

- **RN-020:** somente posições do tipo `FUTURO` com status `ABERTA` aceitam movimentações. O cadastro da posição cria automaticamente a movimentação `ABERTURA` com a quantidade e o `preco_entrada` informados e `data_movimentacao = data_entrada`. Há exatamente uma `ABERTURA` por posição.
- **RN-021:** o preço médio é a média ponderada das entradas — a cada `ABERTURA`/`AUMENTO`, `pm' = (qtd_atual × pm + qtd_mov × preco_mov) / (qtd_atual + qtd_mov)`. Reduções **não** alteram o preço médio. O campo `preco_entrada` permanece como o preço da abertura e não é reaproveitado como preço médio.
- **RN-022:** uma redução com quantidade superior à quantidade atual é rejeitada (`422 Unprocessable Entity`) — não há inversão de lado no MVP. Uma redução que zera a quantidade encerra a posição (`status = ENCERRADA`) na mesma transação.
- **RN-023:** cada redução gera P&L realizado `= (preco_reducao − preco_medio) × qtd_reduzida × sinal`, em moeda original. O `pl_acumulado` do MtM diário passa a ser `mtm_valor` + o realizado acumulado das reduções, convertido pelo câmbio do dia.
- **RN-024:** invariante — `posicao.quantidade` é mantida transacionalmente igual a `Σ quantidade(ABERTURA, AUMENTO) − Σ quantidade(REDUCAO)`.
- **RN-025:** `data_movimentacao` deve ser maior ou igual a `data_entrada`; movimentações são imutáveis no MVP (não há edição nem remoção).

### 7.2 Lançamento de preços

- **RN-007:** não pode haver dois preços de fechamento para o mesmo produto na mesma data.
- **RN-008:** preço deve ser maior que zero.
- **RN-009:** câmbio deve ser maior que zero.
- **RN-010:** ao fazer upload CSV, linhas com erro não bloqueiam linhas válidas — o sistema retorna um relatório de aceitas e rejeitadas.
- **RN-010a:** um preço de referência já utilizado em cálculo (referenciado por `mtm_diario.preco_ref_id`) não pode ser removido; a exclusão é rejeitada com erro `409 Conflict`.

### 7.3 Processamento MtM

- **RN-011:** o motor processa somente posições com status `ABERTA`.
- **RN-012:** se o preço de referência não existir para a data, a posição é marcada como falha e o processamento continua com as outras.
- **RN-013:** o cálculo é idempotente — reprocessar a mesma data atualiza os registros existentes (UPSERT por `posicao_id` + `data_calculo`).
- **RN-014:** posições que vencem no dia são marcadas (`status = VENCIDA`) após o processamento.
- **RN-015:** o resultado em moeda original é convertido para BRL multiplicando pelo câmbio do dia. Para NDF cambial, cuja fórmula já produz resultado em BRL, a moeda é cadastrada como produto com `cambio_brl = 1` (ver §1.4 e §4.3.2), de modo que a conversão é neutra e não há dupla conversão.

### 7.4 Relatórios

- **RN-016:** "posição aberta" mostra todas as posições com status `ABERTA` e seu último MtM disponível.
- **RN-017:** "P&L diário" soma `variacao_dia` de todas as posições para a data.
- **RN-018:** "P&L acumulado" soma `pl_acumulado` de todas as posições abertas na data de referência (inclui o P&L realizado das reduções — RN-023).
- **RN-019:** "exposição líquida" agrupa por produto e calcula soma de `quantidade × sinal`.

---

## 8. Estratégia de testes

### 8.1 Testes unitários

Cobertura prioritária:

- Cada subclasse de `Posicao` tem testes do método `calcularMtm` com pelo menos 4 cenários: comprado/vendido cruzado com mercado a favor/contra.
- O método `sinal` da classe base e de `Perna`.
- Estruturas multi-perna: straddle, collar, bull call spread — resultado esperado calculado manualmente.
- Estrutura com perna comprada e perna vendida na mesma posição (ex.: spread).
- Validações de entrada nos endpoints (quantidade negativa, datas invertidas, strike zero, estrutura sem pernas).
- Idempotência do motor: rodar duas vezes para a mesma data não duplica registros.
- Preço médio e P&L realizado de `Futuro` com movimentações: média ponderada após aumento, redução que mantém o preço médio e gera realizado, redução total que zera e encerra, redução excedente rejeitada, e MtM calculado sobre o preço médio.

**Exemplos:** (no *fat model*, o cálculo é testado **sem banco** — instanciando os
Models via `make()`/`setRawAttributes` sem `save`, ou exercitando os *traits* puros de
`app/Models/Concerns/`; as fórmulas e os valores esperados abaixo permanecem idênticos)

```php
<?php

use App\Models\{Futuro, Opcao, Perna, Movimentacao};

it('futuro comprado com alta gera mtm positivo', function () {
    $futuro = new Futuro(
        id: 1, produtoId: 1, lado: 'COMPRADO', quantidade: 100,
        dataEntrada: new DateTimeImmutable('2026-01-10'),
        dataVencimento: new DateTimeImmutable('2026-09-15'),
        status: 'ABERTA',
        precoEntrada: 1400.00, codigoContrato: 'ZSU24',
    );
    expect($futuro->calcularMtm(1450.00))->toBe(5000.00); // (1450-1400)×100×1
});

it('straddle com mercado acima do strike', function () {
    // CALL ITM + PUT OTM → MtM = (50-30)×100 + (0-28)×100 = 2000 − 2800 = −800
    $opcao = new Opcao(
        id: 2, produtoId: 1, lado: 'COMPRADO', quantidade: 1,
        dataEntrada: new DateTimeImmutable('2026-01-10'),
        dataVencimento: new DateTimeImmutable('2026-09-15'),
        status: 'ABERTA', nomeEstrutura: 'Straddle',
        pernas: [
            new Perna('CALL', 'EUROPEIA', 1450.00, 30.00, 100, 'COMPRADO'),
            new Perna('PUT',  'EUROPEIA', 1450.00, 28.00, 100, 'COMPRADO'),
        ],
    );
    expect($opcao->calcularMtm(1500.00))->toBe(-800.00);
});

it('bull call spread entre os strikes', function () {
    // CALL 1400 comprada (100pt ITM, prêmio 60) + CALL 1450 vendida (50pt ITM, prêmio 30)
    // MtM = (100-60)×100×1 + (50-30)×100×(−1) = 4000 − 2000 = 2000
    $opcao = new Opcao(
        id: 3, produtoId: 1, lado: 'COMPRADO', quantidade: 1,
        dataEntrada: new DateTimeImmutable('2026-01-10'),
        dataVencimento: new DateTimeImmutable('2026-09-15'),
        status: 'ABERTA', nomeEstrutura: 'Bull Call Spread',
        pernas: [
            new Perna('CALL', 'EUROPEIA', 1400.00, 60.00, 100, 'COMPRADO'),
            new Perna('CALL', 'EUROPEIA', 1450.00, 30.00, 100, 'VENDIDO'),
        ],
    );
    expect($opcao->calcularMtm(1500.00))->toBe(2000.00);
});

it('preço médio após aumento', function () {
    $futuro = new Futuro(
        id: 4, produtoId: 1, lado: 'COMPRADO', quantidade: 150,
        dataEntrada: new DateTimeImmutable('2026-01-10'),
        dataVencimento: new DateTimeImmutable('2026-09-15'),
        status: 'ABERTA', precoEntrada: 1400.00, codigoContrato: 'ZSU24',
        movimentacoes: [
            new Movimentacao('ABERTURA', new DateTimeImmutable('2026-01-10'), 100, 1400.00),
            new Movimentacao('AUMENTO',  new DateTimeImmutable('2026-02-10'),  50, 1430.00),
        ],
    );
    expect($futuro->precoMedio())->toBe(1410.00);          // (100×1400 + 50×1430) / 150
    expect($futuro->quantidadeAtual())->toBe(150.0);
    expect($futuro->plRealizado())->toBe(0.00);
    expect($futuro->calcularMtm(1450.00))->toBe(6000.00);  // (1450−1410)×150
});

it('redução mantém o preço médio e gera realizado', function () {
    $futuro = new Futuro(
        id: 5, produtoId: 1, lado: 'COMPRADO', quantidade: 100,
        dataEntrada: new DateTimeImmutable('2026-01-10'),
        dataVencimento: new DateTimeImmutable('2026-09-15'),
        status: 'ABERTA', precoEntrada: 1400.00, codigoContrato: 'ZSU24',
        movimentacoes: [
            new Movimentacao('ABERTURA', new DateTimeImmutable('2026-01-10'), 100, 1400.00),
            new Movimentacao('AUMENTO',  new DateTimeImmutable('2026-02-10'),  50, 1430.00),
            new Movimentacao('REDUCAO',  new DateTimeImmutable('2026-03-10'),  50, 1440.00),
        ],
    );
    expect($futuro->precoMedio())->toBe(1410.00);          // redução não muda o pm
    expect($futuro->quantidadeAtual())->toBe(100.0);
    expect($futuro->plRealizado())->toBe(1500.00);         // (1440−1410)×50×1
});

it('redução em posição vendida inverte o sinal do realizado', function () {
    // Posição VENDIDA: recomprar mais barato realiza lucro.
    $futuro = new Futuro(
        id: 6, produtoId: 5, lado: 'VENDIDO', quantidade: 90,
        dataEntrada: new DateTimeImmutable('2026-01-10'),
        dataVencimento: new DateTimeImmutable('2026-10-31'),
        status: 'ABERTA', precoEntrada: 320.00, codigoContrato: 'BGIV26',
        movimentacoes: [
            new Movimentacao('ABERTURA', new DateTimeImmutable('2026-01-10'), 120, 320.00),
            new Movimentacao('REDUCAO',  new DateTimeImmutable('2026-02-10'),  30, 305.00),
        ],
    );
    expect($futuro->quantidadeAtual())->toBe(90.0);
    expect($futuro->plRealizado())->toBe(450.00);          // (305−320)×30×(−1)
});
```

### 8.2 Testes de integração

- Fluxo completo: cadastrar produto, lançar preço, criar posição, rodar motor, consultar relatório.
- Upload de CSV de preços com linhas válidas e inválidas.
- Reprocessamento do motor: garantir que MtM é atualizado, não duplicado.
- Posição que vence no dia muda de status corretamente.
- Movimentação de futuro: criar posição, registrar aumento e redução, rodar o motor e conferir que `pl_acumulado = mtm_valor + realizado`.
- Redução total encerra a posição (`status = ENCERRADA`) e o motor deixa de processá-la (RN-011/RN-022).
- Redução com quantidade maior que a atual é rejeitada com `422` (RN-022).

### 8.3 Testes de aceitação (UAT)

Roteiros para validação com a mesa de risco:

1. Lançar 10 posições mistas (3 futuros, 2 NDFs, 3 opções, 2 OTC), rodar motor, conferir cálculos manualmente em planilha.
2. Reprocessar o mesmo dia com preço alterado — confirmar que MtM atualiza.
3. Tentar cadastrar posição com dados inválidos — confirmar mensagens claras.
4. Consultar histórico de MtM de uma posição ao longo de 30 dias.
5. Aumentar e reduzir um futuro ao longo de alguns dias, conferindo o preço médio e o P&L realizado manualmente em planilha (atenção ao sinal em posições vendidas).

### 8.4 Metas de cobertura

- Cálculo dos Models (subclasses de posição, pernas e o laço do motor): **≥ 90%** de
  cobertura — testável sem banco (Models via `make()`/`setRawAttributes`, ou *traits*
  puros de `app/Models/Concerns/`).
- Camada de aplicação (serviços com persistência, endpoints): **≥ 70%**. Sem os *fakes*
  de contrato (removidos com os repositórios — DM-2), a orquestração com banco é coberta
  por testes de feature com `RefreshDatabase` (SQLite in-memory / PostgreSQL).
- Total do projeto: **≥ 75%**.

---

## 9. Não-funcionais

### 9.1 Performance

- O motor deve processar 1.000 posições em menos de 30 segundos em hardware modesto (4 vCPU, 8 GB RAM).
- Listagem de posições paginada com 50 itens por página, resposta em menos de 500 ms.
- Relatórios para até 1 ano de histórico devem responder em menos de 5 segundos.

### 9.2 Segurança

- Senhas armazenadas com bcrypt ou argon2id (nunca em texto plano) — hashing nativo do Laravel.
- Autenticação via Laravel Sanctum: sessão (cookie `HttpOnly`/`Secure`/`SameSite` + CSRF) para a interface web Livewire; tokens de acesso de curta duração e revogáveis para a API REST (§5). Caso se exija OAuth2/JWT estrito, usar Laravel Passport.
- Permissões por perfil (aplicadas via Gates/Policies do Laravel):
  - `OPERADOR`: cadastra preços, posições, dispara motor, vê relatórios.
  - `GESTOR`: tudo do operador + remoção de posições.
  - `ADMIN`: tudo + cadastro de usuários e produtos.
- Logs de auditoria para todas as operações de escrita.

### 9.3 Disponibilidade

- O MVP roda em horário comercial. SLA de 99% (downtime aceitável ~7h/mês).
- Backup diário do banco de dados, retenção mínima de 30 dias.

### 9.4 Observabilidade

- Logs estruturados em JSON com nível (INFO, WARN, ERROR) e correlação por request_id.
- Métricas básicas expostas em endpoint `/health` e `/metrics`:
  - Tempo médio de processamento do motor.
  - Número de posições processadas no último ciclo.
  - Tempo de resposta dos endpoints principais.

---

## 10. Roadmap pós-MVP

### Fase 2 — Precificação avançada

- Precificação de opções por Black-76.
- Cálculo de gregas (delta, gamma, vega, theta, rho).
- Persistência de superfície de volatilidade (por produto, vencimento e moneyness).
- Curva de juros simples (uma taxa por moeda e vencimento).

### Fase 3 — Risco quantitativo

- VaR paramétrico e histórico.
- CVaR (Expected Shortfall).
- Stress testing com cenários históricos e hipotéticos.
- Sensibilidades agregadas (delta total do portfólio por produto).

### Fase 4 — Operacional e integração

- Integração com feeds de mercado (Bloomberg, Refinitiv).
- Importação automática de operações via FIX, FpML ou planilhas estruturadas.
- Limites de risco com workflow de aprovação de breach.
- Dashboard executivo com KPIs consolidados.
- Multi-tenant.

### Fase 5 — Quant e simulação

- Motor Monte Carlo para distribuição de P&L.
- Modelagem de correlação entre commodities.
- Cenários climáticos e fundamentais.
- Backtest de estratégias de hedge.

---

## 11. Glossário

| Termo | Definição |
|---|---|
| **MtM** | Mark-to-Market — marcação a mercado, valor justo da posição com base no preço atual. |
| **NDF** | Non-Deliverable Forward — contrato a termo sem entrega física, liquidado financeiramente pela diferença. |
| **OTC** | Over-the-Counter — operação realizada fora de bolsa, diretamente entre as partes. |
| **Call** | Opção de compra — dá ao titular o direito de comprar o ativo pelo strike. |
| **Put** | Opção de venda — dá ao titular o direito de vender o ativo pelo strike. |
| **Strike** | Preço de exercício de uma opção. |
| **Valor intrínseco** | Para uma call: max(spot − strike, 0). Para uma put: max(strike − spot, 0). |
| **Posição comprada** | Posição que ganha quando o preço sobe. |
| **Posição vendida** | Posição que ganha quando o preço cai. |
| **Exposição líquida** | Saldo entre posições compradas e vendidas em um mesmo produto. |
| **P&L** | Profit & Loss — lucro e prejuízo, geralmente expresso como variação diária e acumulada. |
| **P&L realizado** | Resultado efetivado nas reduções de posição: (preço da redução − preço médio) × quantidade reduzida × sinal. |
| **P&L não realizado** | Resultado potencial da quantidade ainda em aberto — corresponde ao MtM. |
| **Movimentação** | Registro de abertura, aumento ou redução da quantidade de uma posição, com data, quantidade e preço. |
| **Preço médio** | Média ponderada dos preços das entradas (abertura + aumentos) de uma posição; não é alterado por reduções. |
| **Polimorfismo** | Capacidade de objetos de classes diferentes responderem ao mesmo método com comportamentos próprios. |
| **Idempotência** | Propriedade pela qual executar uma operação várias vezes produz sempre o mesmo resultado final. |

---

## 12. Operação e desenvolvimento (MVC fat model)

> Esta seção consolida a camada **operacional e de desenvolvimento** do projeto
> (estrutura de pastas, ambiente, comandos, fundação, roteiro de fases e cuidados
> de implementação) — antes mantida no arquivo `CONTEXTO_PROJETO.md`, agora
> incorporada aqui. Em divergência sobre **regras de negócio (§7), modelo de dados
> (§3) ou contratos de API (§5)**, valem aquelas seções. O detalhe por fase vive em
> `specs/passos_dev.md`; a fundação executável, em `specs/spec_parte_0.md`.

### 12.1 Estrutura de pastas (Alternativa A — *fat model*)

- `app/Models/` — Eloquent "gordo": concentra persistência **e** o cálculo de MtM.
- `app/Models/Concerns/` — aritmética reutilizável e cálculos puros (traits).
- `app/Services/` — orquestração e regras transacionais usando Eloquent direto.
- `app/Services/Dados/` — DTOs/read models de saída.
- `app/Facades/` — fachadas convenientes dos serviços.
- `app/Support/Csv/ImportadorPrecosCsv` — ingestão via interface `FontePrecos`
  (único ponto de extensão de ingestão).

**Regra de ouro:** o motor MtM itera `Posicao` e chama `calcularMtm()` **sem
`if`/`switch`** por tipo de instrumento. O único `match` por instrumento fica na
hidratação polimórfica `Posicao::newFromBuilder` (§4.5).

### 12.2 Ambiente Docker

A aplicação e os bancos rodam em **Docker Compose** (paridade de ambiente), com
três serviços:

- `app` — aplicação Laravel (web em `http://localhost:8000`).
- `postgres` — banco de **desenvolvimento**.
- `postgres_test` — banco de **teste**, separado e efêmero.

Comandos:

- Subir / derrubar / rebuild / logs: `docker compose up -d` · `docker compose down`
  · `docker compose build` · `docker compose logs -f app`
- Migrations: `docker compose exec app php artisan migrate` (ou `migrate --seed`
  para carregar dados de demonstração).
- Tinker: `docker compose exec app php artisan tinker`
- Dependências: `docker compose exec app composer install`

### 12.3 Testes e qualidade

- Testes (Pest) contra o banco de teste:
  `docker compose exec -e DB_HOST=postgres_test -e DB_PORT=5432 app composer test`
- Estilo (Pint): `vendor/bin/pint --test` (verifica) / `vendor/bin/pint` (corrige).
- Análise estática (PHPStan/Larastan **nível 8**):
  `vendor/bin/phpstan analyse --memory-limit=512M`
- CI (GitHub Actions): estilo + estática + testes em cada push/PR, com serviço Postgres.
- Metas de cobertura: ver §8.4.

### 12.4 Fundação (Parte 0) — decisões fixadas

- Laravel 13 · PHP 8.3 · PostgreSQL 15+.
- Starter kit oficial **Livewire** com auth **Fortify** built-in (sem WorkOS).
- Locale `pt_BR`.
- Tabela **`usuario`** (não `users`); login pelo campo `login`; senha em `senha_hash`.
- Registro público, verificação de e-mail e reset de senha **desabilitados**.

**DoD da Parte 0:** `docker compose up -d` sobe `app`/`postgres`/`postgres_test`; o
login autentica contra `usuario` por `login`/senha; os testes rodam contra
`postgres_test`; Pint e PHPStan passam; CI verde.

### 12.5 Roteiro de implementação (fases)

> Detalhe completo (objetivo, entregáveis, tarefas, DoD) em `specs/passos_dev.md`.
> Estas são as **fases de desenvolvimento** — não confundir com o roadmap de
> produto pós-MVP do §10.

Fase 0 Fundação · 1 Esqueleto MVC + migrations · 2 Models + cálculo · 3 Testes
unitários · 4 Produtos & Preços · 5 Posições & movimentações · 6 Motor MtM ·
7 Relatórios · 8 Seed & demo · 9 Testes de integração · 10 RBAC & autenticação ·
11 Segurança · 12 Não-funcionais · 13 Regressão & CI gates · 14 Hardening & entrega.

### 12.6 Motor MtM — operação

- Processar uma data manualmente:
  `docker compose exec app php artisan motor:processar --data=YYYY-MM-DD`
  (sem `--data`, processa **hoje**).
- Agendamento: dias úteis às 19:00 (`routes/console.php`); em produção, o cron do
  servidor chama `php artisan schedule:run`. As regras de cálculo estão em §4.4/§7.3.

### 12.7 Cuidados de desenvolvimento

- **Não** ler, editar ou usar `historic-plans/` (arquivo morto, desatualizado).
- Preservar **MVC fat model**; não introduzir DDD em camadas, repositórios ou
  domínio separado.
- Preservar o polimorfismo do motor (condicionais por tipo não pertencem ao motor).
- Usar transações + `lockForUpdate` nos serviços de movimentação.
- Movimentações são **imutáveis** no MVP (RN-025).
- CSV: validar tipos, tamanho/linhas e prevenir *formula injection* (CWE-1236).
- Usar **PostgreSQL** para recursos que o SQLite não cobre bem: índice parcial,
  `NUMERIC`, `JSONB`.
- **Commits:** nunca como Claude — apenas como o usuário.

### 12.8 Documentos do projeto

- `specs/requisitos.md` — esta especificação (fonte da verdade).
- `specs/passos_dev.md` — roteiro de fases 0..14.
- `specs/spec_parte_0.md` e demais `spec_parte_N.md` — specs executáveis por fase.
- `CLAUDE.md` / `AGENTS.md` — instruções para agentes e comandos.
- `mock_telas/` — mock interativo das telas.

---

## 13. Aprovação e versionamento

| Versão | Data | Autor | Mudança |
|---|---|---|---|
| 1.0 | 2026-05-25 | Equipe de produto | Versão inicial do MVP |
| 1.1 | 2026-05-26 | Equipe de produto | Suporte a estruturas de opção multi-perna (tabela `posicao_opcao_perna`, classe `Perna`, exemplos de straddle/spread/collar) |
| 1.2 | 2026-06-12 | Equipe de produto | Revisão técnica: convenção cambial do NDF (moeda como produto com `cambio_brl = 1`), tabela `motor_execucao`, UML atualizado com `Perna`, `pl_acumulado` documentado, correções de consistência (prêmio de perna, RN-006, RN-010a, RN-004e, POST encerrar, aliases no SQL) |
| 1.3 | 2026-06-13 | Equipe de produto | Movimentações de posição FUTURO: tabela `posicao_movimentacao` (com `ABERTURA` automática no cadastro), preço médio ponderado derivado das movimentações (`preco_entrada` preservado como preço da abertura), MtM calculado sobre o preço médio, P&L realizado nas reduções e `pl_acumulado = mtm_valor + realizado` (RN-020 a RN-025; RN-001 e RN-018 ajustadas; endpoints `GET/POST /posicoes/{id}/movimentacoes`; classe `Movimentacao` e properties em `Futuro`; novos testes §8; telas §6.1/§6.2/§6.4; glossário §11) |
| 1.4 | 2026-06-13 | Equipe de produto | Migração de stack para **Laravel**: §2.2 atualizado (PHP 8.2+/Laravel 11/Eloquent/Migrations/Blade+Livewire/Sanctum; PostgreSQL mantido); código de referência do §4 (classes de domínio, motor e repositório) convertido de Python para PHP preservando as fórmulas; exemplos de teste do §8.1 convertidos para Pest; §9.2 (autenticação) ajustado para Sanctum. Regras de negócio (§7), modelo de dados (§3) e contratos da API (§5) permanecem inalterados |
| 1.5 | 2026-06-17 | Equipe de produto | Re-arquitetura **DDD em camadas → MVC nativo com *fat model*** (Alternativa A): §2.2 (ORM) e §4 (hierarquia de classes) passam de domínio em PHP puro + repositórios para **Models Eloquent gordos** com cálculo de MtM embutido; o motor (§4.4) itera Models direto e usa `MtmDiario::updateOrCreate` (sem contratos/repositórios); o `match` da factory (§4.5) vira `newFromBuilder`, preservando o polimorfismo; salvaguardas de cálculo puro/*traits* e namespaces (`App\Models\`) ajustados em §4 e §8.1/§8.4. Regras de negócio (§7), modelo de dados (§3) e contratos da API (§5) permanecem inalterados |
| 1.6 | 2026-06-17 | Equipe de produto | Atualização de stack para **Laravel 13 / PHP 8.3+** (§2.2; suportado 8.3–8.5). Acompanha a especificação da fundação em `specs/spec_parte_0.md` (starter kit Livewire + Flux UI adaptado a `usuario`, Docker, CI GitHub Actions). Regras de negócio (§7), modelo de dados (§3) e contratos da API (§5) permanecem inalterados |
| 1.7 | 2026-06-20 | Equipe de produto | Incorporada a **camada de operação e desenvolvimento** como nova §12 (estrutura de pastas, ambiente Docker, testes/qualidade, fundação Parte 0, roteiro de fases, operação do motor e cuidados de desenvolvimento), antes mantida no arquivo `CONTEXTO_PROJETO.md` — agora removido. Versionamento renumerado para §13. Regras de negócio (§7), modelo de dados (§3) e contratos da API (§5) permanecem inalterados |

---

**Fim do documento.**
