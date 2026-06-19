# Parecer de Arquitetura: Análise Crítica da Parte 2 (Models e MtM)

Analisando detalhadamente a **Parte 2** (`specs/spec_parte_2.md`), identifiquei várias falhas técnicas, contradições e potenciais bugs críticos na implementação proposta com o Laravel (Eloquent).

Aqui estão os pontos de atenção e vulnerabilidades encontrados para revisão:

### 1. Risco de N+1 Silencioso (Falta de Salvaguarda)
A decisão **D-201** estabelece que o cálculo é puro e que o *eager loading* (carregamento das relações) é responsabilidade exclusiva dos *Services*.
*   **A Falha:** Nos Models, métodos como `$this->futuro->preco_entrada` (em `Futuro`, §4.4) ou `$this->movimentacoes->isNotEmpty()` não têm nenhuma proteção caso o Service esqueça do *eager loading*. Se esquecerem, o Eloquent vai disparar silenciosamente *Lazy Loading*, gerando milhares de queries no loop do motor e derrubando o banco de dados.
*   **Correção:** A especificação precisa exigir a inclusão de `Model::preventLazyLoading(true)` no `AppServiceProvider::boot()`. Isso fará a aplicação "estourar" um erro em ambiente local/testes se alguém acessar uma relação não carregada, garantindo a promessa de pureza (D-201).

### 2. O Bug Silencioso das Chaves Estrangeiras do Eloquent
A decisão **D-MVC-1** orienta o uso de relações `hasOne` a partir das subclasses. Ex: `Futuro` define `hasOne(PosicaoFuturo::class)`.
*   **A Falha:** Por padrão, o Eloquent assume que a chave estrangeira em `posicao_futuro` tem o nome da classe pai no formato snake_case: `futuro_id`. Mas o banco de dados (§3.2.4 do `requisitos.md`) diz que a chave estrangeira se chama `posicao_id`. 
*   **Correção:** A especificação deve deixar explícito que os métodos de relacionamento precisam do segundo argumento: `return $this->hasOne(PosicaoFuturo::class, 'posicao_id');`. Se isso for omitido, o relacionamento vai quebrar na hora da execução.

### 3. Aritmética Financeira e o "Float" (D-MVC-2 / D-712)
A especificação insiste em centralizar a conversão para `float` no trait `ConverteDecimais`.
*   **A Contradição:** Fazer um *cast* para `float` e operar internamente com floats *antes* do arredondamento (como em `(1418.65 * 100.0)`) é a raiz de erros em sistemas financeiros devido ao problema do ponto flutuante IEEE 754 (ex: `0.1 + 0.2 != 0.3`). 
*   **Correção:** Mesmo que eles arredondem no final, a conta no "meio" do caminho (no `replay`, por exemplo) gera imprecisão. Deveria usar a extensão `bcmath` (`bcadd`, `bcmul`) dentro desse trait, em vez de um simples `(float) $valor`.

### 4. Ordem Cronológica Ambígua no *Replay* de Movimentações (§4.2)
No método `reproduzir()` do trait `ReproduzMovimentacoes`, o array é ordenado por data e garantindo que `ABERTURA` venha primeiro:
`[$a['data'], $a['tipo'] !== 'ABERTURA'] <=> [$b['data'], $b['tipo'] !== 'ABERTURA']`
*   **A Falha (Condição de Corrida de Regras):** O que acontece se houver um `AUMENTO` e uma `REDUCAO` lançados no **mesmo dia**? O PHP fará um *stable sort* (mantendo a ordem em que vieram do banco). Se a `REDUCAO` rodar antes do `AUMENTO`, o sistema usará o Preço Médio velho para calcular o lucro, e só depois atualizará o Preço Médio. O resultado financeiro será contabilmente incorreto.
*   **Correção:** A ordenação precisa de um critério de desempate determinístico adicional para eventos no mesmo dia (como o `id` da movimentação): `[$a['data'], $a['tipo'] !== 'ABERTURA', $a['id'] ?? 0]`.

### 5. Configuração Incompleta de Autenticação (Fortify/Sanctum)
A seção 4.9 orienta a criação do model `Usuario` usando a tabela `usuario` e o campo `senha_hash`. É dito para usar `$this->getAuthPassword()` retornando `senha_hash`.
*   **A Falha:** O Laravel e o Fortify (usados no reset de senha, verificações de hash internas, etc.) em muitos pontos usam internamente `getAuthPasswordName()`. Apenas dar override em `getAuthPassword()` muitas vezes não é suficiente nas versões recentes.
*   **Correção:** O Model `Usuario` também precisa fazer o override de `public function getAuthPasswordName() { return 'senha_hash'; }`.

### 6. Contradição no Retorno de `sinal()`
Nos models, o método `sinal()` faz: `return $this->lado === 'COMPRADO' ? 1 : -1;`.
*   **A Falha:** Em `Posicao` base (que não faz o cast explícito de `$lado`), se a string vier diferente do banco ("Comprado", ou um enum com espaço extra) ou nula, ele assumirá silenciosamente `-1` (Vendido). Como se trata de posição financeira que inverte o sinal do lucro/prejuízo, assumir um default para o *fallback* de um ternário é altamente perigoso.
*   **Correção:** Deveria ser utilizado um `match` restrito:
```php
return match($this->lado) {
    'COMPRADO' => 1,
    'VENDIDO' => -1,
    default => throw new \DomainException("Lado da posição inválido: {$this->lado}")
};
```
