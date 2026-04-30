# Deploy dos endpoints na Azure

Este projeto publica apenas os endpoints PHP usados pelo app Expo. A pasta precisa ficar disponível na Azure como:

```text
https://apdv.azurewebsites.net/endpoints
```

## Arquivos de deploy

- `.github/workflows/deploy-azure.yml`: workflow do GitHub Actions.
- `endpoints/`: endpoints PHP enviados para a Azure.
- `web.config`: configuracao IIS/App Service para default document.
- `deploy.env.example`: variaveis que devem existir em Configuration > Application settings.
- `scripts/build-azure-package.ps1`: monta localmente o mesmo zip enviado pelo workflow.

## Variaveis obrigatorias na Azure

Configure em App Service > Configuration > Application settings:

```text
DB_HOST
DB_DATABASE
DB_USERNAME
DB_PASSWORD
```

Para este projeto, os valores esperados seguem o banco da aplicacao de referencia `C:\xampp\htdocs\pec-rodrigo`.

## Deploy via GitHub Actions

1. No GitHub, crie o secret `AZURE_WEBAPP_PUBLISH_PROFILE` com o conteudo do publish profile da Azure.
2. Faça push na branch `main`.
3. O workflow valida os PHPs, monta a pasta `deploy/` e publica na Web App `apdv` usando Kudu VFS.

O deploy usa upload arquivo a arquivo porque o Zip Deploy da Azure pode falhar na etapa `Extract zip` em alguns App Services.

Se o deploy falhar, o workflow mostra o status HTTP de cada arquivo enviado.

## Teste depois do deploy

```text
https://apdv.azurewebsites.net/endpoints/mobile/boletos
```

O retorno esperado deve conter `ok: true`, `items`, `filter_units`, `filters` e `list_total_amount`.
