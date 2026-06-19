# Crítica de Arquitetura Sênior — Spec Parte 3 (Testes Unitários / Motor MtM)

Analisando rigorosamente a `spec_parte_3.md` à luz do modelo arquitetural adotado (MVC nativo do Laravel com *fat models* focados na retenção do cálculo financeiro), detecto severas fragilidades. A especificação tentar blindar a lógica com um "teste sem banco" e "traits puros", mas o contorcionismo técnico revelado nesta suíte de testes atesta o preço de se negligenciar camadas puras de domínio. 

Abaixo, detalho os problemas da abordagem, mapeando os pontos de avaliação obrigatórios da nossa metodologia crítica.

---

## 1. Gargalos de Manutenção no Futuro (Sufocamento pelo *Fat Model*)

A tentativa de testar cálculos complexos em classes de persistência acopla a estrutura dos testes à tecnologia do ORM, gerando os seguintes gargalos:

* **Quebra do SRP (Single Responsibility Principle) e Falsa Pureza:**
  A decisão **D-301** orgulha-se de usar `make()` e `setRelation()` para evitar a inicialização do banco. Porém, os testes estão instanciando todo o peso do `Illuminate\Database\Eloquent\Model` (boot de casts, observers, mutations, timestamps) apenas para multiplicar `(mercado - preco_entrada) * quantidade`. A lógica de negócio está amarrada aos nomes exatos das relações do banco de dados (ex: `$o->setRelation('otc', PosicaoOtc::make(...))`). Quando a estrutura da tabela mudar no futuro, toda a suíte unitária financeira quebra, mesmo que a matemática do derivativo continue idêntica.
* **Traits de Falsa Conveniência (Inchaço Oculto):**
  A seção **4.1** revela que, para testar a matemática sem acoplar ao model completo, a spec exige criar uma classe anônima em tempo de execução: `$c = new class { use ConverteDecimais; };`. 
  Do ponto de vista arquitetural, se uma função é 100% estática e não opera sobre o estado interno da classe alvo, **ela não deveria ser um trait**. Traits implicam mistura de comportamentos no escopo do objeto. A ausência de Value Objects (ex: classe `Money` ou `MoneyCalculator`) força o espalhamento de funções aritméticas e conversores procedurais pelo código.
* **Acoplamento de Mocks de Relações:**
  O setup dos dados nas seções **4.3 e 4.4** para instanciar as pernas de opções ou estruturas multi-perna requer a injeção artificial de Collections via `setRelation('pernas', collect([...]))`. A legibilidade e a manutenção desta suíte de testes ficarão progressivamente caóticas. Para cada novo tipo de produto financeiro criado, o desenvolvedor gastará 80% do tempo preenchendo as entranhas do ORM no teste em vez de focar na fórmula financeira.

## 2. Casos de Uso Incompletos (Pontos Cegos da Especificação)

A spec ignora variações marginais que fatalmente trarão complexidade acidental ao sistema em produção:

* **Cálculos Intermediários com "Limitação Float" (D-305):**
  A spec afirma que "não se introduz bcmath na Fase 3" e assume que a aritmética nativa em `float` será suficiente. No entanto, o cálculo do *Preço Médio (PM)* — que envolve somas ponderadas e divisão — é altamente vulnerável a dízimas. Em vez de lidar com a raiz do problema (precisão financeira estrita), a spec propõe em D-305 mitigar falhas nos testes unitários usando `toEqualWithDelta($v, 1e-9)`. Um sistema de derivativos no mundo real não pode ter casos de aceite baseados em "margem de erro de aproximação float"; as perdas de centavos agregados em alto volume trarão litígios de auditoria (P&L irreal), caracterizando uma grave lacuna no desenho dos casos de uso de borda.
* **Conversão de Câmbio Deslocada (Ausência BRL):**
  A fase estipula explicitamente que a conversão de BRL e câmbio é tratada apenas na Fase 6, pela camada de Serviço. Isso significa que o núcleo de cálculo e a modelagem financeira da `spec_parte_3` ignoram que diferentes pernas em uma estratégia possam operar sob bases monetárias diferentes. Quando o negócio demandar agregação multi-moeda (MtM global), o *fat model* se revelará incompetente, pois seu `calcularMtm` devolve um número escalar desconectado da moeda referencial, sobrecarregando novamente o *Service* com orquestração financeira que deveria ser do domínio.

## 3. Contradições (Paradoxos Lógicos)

Há colisões lógicas diretas entre o discurso do "MVC pragmático" e as escolhas de implementação propostas:

* **Contradição do Determinismo de Eventos (Seção 4.2):**
  A spec comemora o "desempate de data por `id`" como salvação para o *replay* de eventos. Entretanto, basear a lógica financeira no auto-incremento do banco (`id`) fere gravemente o conceito de reprodução temporal determinística fora da base de dados. Se os eventos chegam fora de ordem via fila (Kafka, RabbitMQ) ou foram migrados de outro banco com novos IDs, o `id` perde sincronia com a realidade transacional. Regras de ordem financeira deveriam depender de timestamps com milissegundos ou sequenciais gerados pelo negócio, e nunca da chave primária relacional auto-incrementada.
* **Testes Sem Banco vs Mapeamento Falso:**
  A diretriz de **D-306** (`newFromBuilder` sem `save()`/`get()`) cria uma falsa sensação de segurança. O polimorfismo sendo testado "na mão" através de um `stdClass` (`$model = (new Posicao)->newFromBuilder(...)`) assegura que o método do PHP funciona, mas **não garante que a hidratação polimorfica funcionará com a representação relacional em produção**. Já que o polimorfismo em Laravel depende ativamente do `getAttributes` vindo da base de dados, a suíte unitária cobre um cenário sintético impossível de ocorrer nativamente sem o banco. O Eloquent está sendo simulado de forma ingênua.
* **Testes de Validação Omitidos do Núcleo:**
  Na seção 1, a spec joga validações vitais como "quantidade negativa, datas invertidas, strike zero" para os *Form Requests* e *Services* (Fases 4/5). No entanto, são justamente essas invalidações matemáticas que protegem os cálculos do núcleo. Permitir que o Model (`Futuro` ou `Opcao`) instancie um "strike zero" sem falhar o cálculo (fail-fast), e confiar que uma fina camada de request HTTP vai proteger a matemática, é um desastre iminente de *Domain Driven Design* (mesmo considerando ser uma arquitetura anêmica MVC). Os modelos "gordos" do Laravel deveriam no mínimo ser imunes a estados financeiros inválidos se de fato pretendem abrigar a lógica financeira do sistema.

---
### Conclusão

A `spec_parte_3.md` tenta desesperadamente obter os benefícios de uma camada de domínio pura (Value Objects, Entities independentes) extraindo testes "puros", simulando relacionamentos e mascarando dízimas flutuantes. O resultado é um híbrido frágil: **uma suíte unitária altamente acoplada ao ORM e isolada apenas de forma sintética**. Assim que a complexidade de regras e relacionamentos expandir, o custo de manutenção da configuração `make()`/`setRelation()` do *fat model* excederá em muito o que custaria extrair o motor MtM para calculadoras financeiras em PHP puro que ignorassem o framework.
