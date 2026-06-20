# NeverVenture - Gestão de Risco de Mercado para Commodities

NeverVenture é um MVP (Minimum Viable Product) de gestão de risco de mercado para commodities, focado no ciclo pós-trade: cadastro/importação de posições, lançamento/importação de preços de referência, processamento diário de MtM (Mark-to-Market), histórico de P&L e relatórios consolidados.

## Tecnologias Utilizadas
- **Core**: PHP 8.3 + Laravel 13
- **Frontend**: Livewire 4 + Flux UI + Tailwind CSS
- **Banco de Dados**: PostgreSQL 15+ (Dev e Testes)
- **Qualidade/Testes**: Pest, Pint, PHPStan/Larastan
- **Infraestrutura**: Docker & Docker Compose

---

## 🚀 Passo a Passo para Iniciar o Projeto

Siga os passos abaixo para subir a aplicação em seu ambiente local:

### Passo 1: Clonar o Repositório e Configurar as Variáveis de Ambiente
Certifique-se de que possui um arquivo `.env` configurado. Se não possuir, copie o modelo:
```bash
cp .env.example .env
```
*(Nota: O arquivo `.env` já vem pré-configurado para conectar ao banco PostgreSQL do Docker).*

### Passo 2: Subir a Stack Docker
Inicie os containers da aplicação e dos bancos de dados (desenvolvimento e teste):
```bash
docker compose up -d
```
Isso iniciará três serviços:
1. `app` (servidor web exposto em `http://localhost:8000`)
2. `postgres` (banco de dados PostgreSQL de desenvolvimento)
3. `postgres_test` (banco de dados PostgreSQL dedicado a testes)

### Passo 3: Executar Migrations e Seeders
Com os containers ativos, execute as migrations do banco de dados e os dados de demonstração (seed):
```bash
docker compose exec app php artisan migrate --seed
```

### Passo 4: Acessar a Aplicação
Acesse a aplicação no seu navegador em:
👉 **[http://localhost:8000](http://localhost:8000)**

Para efetuar o login, utilize uma das credenciais de demonstração (senha padrão: `password`):
- **Administrador**: login `admin` / senha `password`
- **Gestor**: login `gestor` / senha `password`
- **Operador**: login `operador` / senha `password`

---

## 🛠️ Comandos Úteis do Docker

- **Subir os containers (modo background)**:
  ```bash
  docker compose up -d
  ```
- **Derrubar os containers**:
  ```bash
  docker compose down
  ```
- **Reconstruir as imagens Docker**:
  ```bash
  docker compose build
  ```
- **Visualizar os logs do container da aplicação**:
  ```bash
  docker compose logs -f app
  ```

---

## 🧪 Comandos de Desenvolvimento e Qualidade

Todos os comandos de desenvolvimento devem ser executados através do container `app` ou localmente (se possuir o ambiente PHP configurado no host).

### 1. Testes Automatizados (Pest)
A suite de testes utiliza um banco separado (`postgres_test`) para garantir velocidade e consistência.

#### Executar Testes Completos
- **No Container (Recomendado)**:
  ```bash
  docker compose exec -e DB_HOST=postgres_test -e DB_PORT=5432 app composer test
  ```
- **No Host**:
  ```bash
  vendor/bin/pest
  ```

#### Opções Úteis de Teste
- **Rodar com relatório de cobertura**:
  ```bash
  docker compose exec -e DB_HOST=postgres_test -e DB_PORT=5432 app vendor/bin/pest --coverage
  ```
- **Rodar apenas testes de um arquivo**:
  ```bash
  docker compose exec -e DB_HOST=postgres_test -e DB_PORT=5432 app vendor/bin/pest tests/Unit/Models/PosicaoTest.php
  ```
- **Rodar apenas testes unitários**:
  ```bash
  docker compose exec -e DB_HOST=postgres_test -e DB_PORT=5432 app vendor/bin/pest tests/Unit
  ```
- **Rodar apenas testes de integração/feature**:
  ```bash
  docker compose exec -e DB_HOST=postgres_test -e DB_PORT=5432 app vendor/bin/pest tests/Feature
  ```
- **Rodar com output detalhado (verbose)**:
  ```bash
  docker compose exec -e DB_HOST=postgres_test -e DB_PORT=5432 app vendor/bin/pest -v
  ```
- **Parar no primeiro teste que falhar**:
  ```bash
  docker compose exec -e DB_HOST=postgres_test -e DB_PORT=5432 app vendor/bin/pest --bail
  ```

#### Testes da Parte 4 — Produtos & Preços
- **Toda a suíte do módulo (API + Livewire + importador)**:
  ```bash
  docker compose exec -e DB_HOST=postgres_test -e DB_PORT=5432 app vendor/bin/pest tests/Feature/Produtos tests/Feature/Precos tests/Unit/Csv
  ```
- **Segurança/parsing do importador CSV (sem banco)**:
  ```bash
  docker compose exec -e DB_HOST=postgres_test -e DB_PORT=5432 app vendor/bin/pest tests/Unit/Csv/ImportadorPrecosCsvTest.php
  ```
- **API e tela de Produtos**:
  ```bash
  docker compose exec -e DB_HOST=postgres_test -e DB_PORT=5432 app vendor/bin/pest tests/Feature/Produtos
  ```
- **API, tela e upload CSV de Preços**:
  ```bash
  docker compose exec -e DB_HOST=postgres_test -e DB_PORT=5432 app vendor/bin/pest tests/Feature/Precos
  ```
- **Filtrar por nome do caso** (ex.: regra RN-010a):
  ```bash
  docker compose exec -e DB_HOST=postgres_test -e DB_PORT=5432 app vendor/bin/pest tests/Feature/Precos --filter="RN-010a"
  ```

### 2. Estilo de Código (Laravel Pint)
O Pint é utilizado para formatar o código seguindo as convenções do Laravel.
- **Verificar estilo no Container**:
  ```bash
  docker compose exec app vendor/bin/pint --test
  ```
- **Corrigir estilo automaticamente no Container**:
  ```bash
  docker compose exec app vendor/bin/pint
  ```
- **Executar no Host**:
  ```bash
  vendor/bin/pint
  ```

### 3. Análise Estática (PHPStan / Larastan)
Utilizado para verificar a tipagem e erros potenciais de execução no nível 8.
- **Executar análise no Container**:
  ```bash
  docker compose exec app vendor/bin/phpstan analyse --memory-limit=512M
  ```
- **Executar análise no Host**:
  ```bash
  vendor/bin/phpstan analyse --memory-limit=512M
  ```

### 4. Outros Comandos Artisan
- **Acessar o terminal interativo (Tinker)**:
  ```bash
  docker compose exec app php artisan tinker
  ```
- **Instalar dependências do PHP (Composer)**:
  ```bash
  docker compose exec app composer install
  ```
- **Limpar o cache do Laravel**:
  ```bash
  docker compose exec app php artisan optimize:clear
  ```

---

## 📁 Documentações Importantes
- [Requisitos Funcionais e Regras de Negócio](specs/requisitos.md)
- [Guia de Fases de Desenvolvimento](specs/passos_dev.md)
- [Guia de Uso — Produtos & Preços (Parte 4)](docs/uso-parte-4.md)
- [Diretrizes para Agentes de IA](AGENTS.md)
- [Contexto Geral do Projeto](CONTEXTO_PROJETO.md)
