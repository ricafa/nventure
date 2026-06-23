# Parecer de Arquitetura: Análise Crítica do MVP NeverVenture

Como arquiteto sênior revisando o documento de especificação (v1.6), vejo que o time se esforçou para simplificar o MVP. No entanto, a adoção do **MVC Nativo com Fat Model** e algumas decisões de regras de negócios escondem "bombas-relógio" técnicas e conceituais. Abaixo apresento uma crítica severa sobre os gargalos, lacunas operacionais e contradições do modelo proposto.

## 1. Gargalos de Manutenção no Futuro (O Problema do "Fat Model")

A decisão recente (v1.5) de remover as camadas de domínio e acoplar a lógica de negócio diretamente no Eloquent é extremamente arriscada para um sistema financeiro (cálculo de derivativos e P&L).

*   **Antipattern de Herança no Eloquent (`newFromBuilder` hack):** A seção 4.5 força o Eloquent a realizar Polimorfismo de Tabela Única (STI) sobrescrevendo o método `newFromBuilder` para retornar instâncias filhas (`Futuro`, `Opcao`, etc). Embora pareça elegante no código para evitar `if/else`, o Eloquent **não foi desenhado para isso**. Com o tempo, a equipe vai enfrentar bugs bizarros ao usar features nativas como `Eager Loading` de relacionamentos cruzados, `Model Factories` nos testes, eventos do ciclo de vida (`saving`, `updating`), e falhas na serialização/hidratação do Livewire.
*   **Problema de Performance (O "Replay" de Futuros):** Para calcular o preço médio (`precoMedio()`) no modelo `Futuro`, o sistema carrega na RAM todas as movimentações e faz um `foreach` recalculando o histórico (o método `replay()`). Com o motor MtM processando milhares de posições por dia, isso significa que para uma posição com 300 movimentações ativas, o sistema fará um *eager loading* maciço e processamento redundante em memória todos os dias. A meta do requisito **9.1** (1.000 posições em < 30s) dificilmente será alcançada quando a base inchar.
*   **A "Falsa Pureza" dos Testes e a Armadilha dos Floats:** A documentação clama que o código é "testável sem banco", mas os cálculos financeiros são feitos usando o tipo `float` nativo do PHP (ex: `$quantidade`, `$preco`). Cálculos financeiros exigem precisão absoluta. Ao usar `float`, cedo ou tarde dízimas criarão erros de arredondamento em reduções fracionadas e no fechamento do P&L (ex: `1418.65 * 100.0` vs `1418.65000001`). O sistema já deveria prever o uso da biblioteca `BCMath` ou do padrão `Money` no backend.

## 2. Casos de Uso Incompletos (Lacunas Operacionais)

As especificações falham em abordar a vida real do *Backoffice* de uma mesa de risco:

*   **A "Imutabilidade Inflexível" (RN-025):** A especificação proíbe a edição ou remoção de movimentações. **Isto é um erro fatal de UX em sistemas financeiros.** Se o operador digitar uma redução com a quantidade errada (`100` em vez de `10`) e salvar, não há mecanismo de estorno. A especificação sugere que a posição só pode ser deletada se "não tiver MtM". E se o erro acontecer no D+5 de uma posição aberta? O usuário ficará com o portfólio "sujo", exigindo que um DBA altere a tabela na mão para arrumar o preço médio, o que fere o requisito de auditoria (2.3). É imperativo existir o conceito de "Movimentação de Estorno" ou "Cancelamento".
*   **Encerramento Parcial com Resto (RN-022):** Uma redução igual à quantidade atual encerra a posição. Porém, com floats de precisão `NUMERIC(18,4)`, um erro mínimo de ponto flutuante na entrada de dados pode fazer a quantidade chegar a `0.0001`, deixando a posição "zumbi" (não encerrada e poluindo os relatórios). Falta uma tolerância de fechamento (epsilon) ou fluxo manual de encerramento.
*   **Preços Substitutos / Carry-over:** Na RN-012, se não houver preço de referência, o motor marca como "falha" e não processa. Na prática de mercado, se um ativo não tem cotação em um feriado, o risco assume a cotação do dia útil anterior (carry-over/forward fill). Ignorar a posição criará "buracos" no MtM do portfólio: o dashboard mostrará uma exposição total irreal naquele dia.

## 3. Contradições Lógicas nas Regras

Existem paradoxos entre os objetivos pretendidos e as fórmulas matemáticas da arquitetura:

*   **A "Mistura" de Risco de Preço e Câmbio (§3.2.8 / RN-015):** A especificação aceita que a `variacao_dia` misture efeito de preço e efeito de câmbio (já que é calculada usando o BRL). **Contradição:** Uma mesa de derivativos de commodities precisa saber "estamos perdendo dinheiro porque a soja caiu, ou porque o dólar caiu?". Ao registrar no banco de dados apenas o resultado já "sujo" da multiplicação do câmbio na coluna `variacao_dia`, a aplicação perde a rastreabilidade do risco primário. Mesmo no MVP, isso invalidará relatórios de Exposição Líquida confiáveis.
*   **Inconsistência da Quantidade da Opção (RN-004e vs §3.2.3):** A documentação define que na posição de `OPCAO` a coluna `quantidade` na tabela-mãe deve ser = `1` e o `lado` meramente informativo, e que a matemática de verdade acontece nas `pernas`. **Contradição:** Na listagem de posições e relatórios de "Posição Aberta" (RN-016), exibir "1 contrato" para um *straddle* que na verdade envolve a exposição de 10.000 sacas de soja será completamente inútil para o gestor. A tabela `posicao` deveria agrupar a "quantidade nocional total" ou a UI será enganosa.

## 4. Sugestões de Correção Imediata

1. **Abandone floats primitivos:** Substitua o uso de `float` do PHP por cálculos com `BCMath` em toda a matemática de P&L e quantidades.
2. **Implemente o Estorno:** Mesmo mantendo a imutabilidade das linhas atuais, adicione um tipo de movimentação `ESTORNO` para reverter cálculos incorretos de forma auditável e autônoma pelo usuário.
3. **Cache de Posição de Futuros:** Repense o `replay()` em memória do `Futuro` ou crie cache (na tabela `posicao_futuro`) para a `quantidade_atual` e o `preco_medio` a cada nova transação, permitindo que o motor obtenha os valores consolidados em complexidade O(1).

## 5. Adendos por fase (itens adiados deliberadamente)

*   **(Fase 7 — Relatórios, BX-5 da crítica) Janela/paginação do `historico-mtm`:** o endpoint
    `GET /relatorios/historico-mtm?posicao_id=` carrega **todo** o histórico de MtM da posição sem
    janela nem limite. Para 1 ano de dados (§9.1) é aceitável no MVP, mas quando a base de
    `mtm_diario` inchar isso vira payload grande e consulta pesada. Adicionar paginação/recorte por
    janela (`?de=&ate=` ou `limit`) é refinamento de **escala (Fase 12)**, junto com o *load test*
    formal e a denormalização do preço médio do FUTURO.
