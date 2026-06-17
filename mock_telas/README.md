# NeverVenture - Risco Commodities (Mock de Telas)

Este repositório contém o mock interativo de telas para o MVP de **Risco · Commodities**. O projeto é construído em HTML5, CSS customizado e React, processando a compilação do JSX diretamente no navegador via Babel Standalone.

---

## Como Executar o Projeto

Como o projeto faz requisições para carregar scripts externos (React, Babel) e arquivos JSX locais, é recomendável executá-lo através de um servidor local de desenvolvimento para evitar restrições de segurança do protocolo `file://` (CORS).

### 1. Utilizando Node.js (Recomendado)

Você pode iniciar um servidor estático rápido na porta `8080` usando o `http-server` com o `npx`:

```bash
npx -y http-server -p 8080
```

Se você estiver em um ambiente **Windows** usando o PowerShell e receber um erro de política de execução (`PSSecurityException`), execute o comando pelo terminal CMD tradicional ou ignore a política usando a flag correspondente:

**Pelo CMD:**
```cmd
cmd.exe /c npx -y http-server -p 8080
```

**Pelo PowerShell (ignorando a política de execução temporariamente):**
```powershell
powershell -ExecutionPolicy Bypass -Command "npx -y http-server -p 8080"
```

---

## Como Acessar o MVP

Após iniciar o servidor de desenvolvimento, abra o navegador no seguinte endereço:

👉 **[http://127.0.0.1:8080/Risco%20Commodities.html](http://127.0.0.1:8080/Risco%20Commodities.html)**

---

## Estrutura de Arquivos do Projeto

* `Risco Commodities.html` - Ponto de entrada do aplicativo (HTML, CSS global, importações de CDNs).
* `app.jsx` - Componente principal de Shell/Layout e controle das rotas/navegação.
* `components.jsx` - Biblioteca de componentes utilitários (ícones, botões, modais).
* `screens.jsx`, `screens2.jsx`, `screens3.jsx` - Módulos de telas do sistema (Dashboard, Produtos, Preços, etc.).
* `data.js` - Dados mockados do sistema (dados de usuário, limites de risco, exposições, etc.).
