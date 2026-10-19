# Integração MCP com ChatGPT

O Pergunta.Online expõe um servidor MCP remoto **Streamable HTTP** implementado em PHP 8.1+, sem Node, Composer ou alteração na API REST existente.

## Configuração

Configure **app_url** em `php/config.php` com o endereço público HTTPS da instalação: `https://pergunta-online.diegoramos.tech`. A implantação deve servir a pasta `php/` como document root e habilitar o `mod_rewrite` do Apache. O endereço do servidor MCP é:

```text
https://pergunta-online.diegoramos.tech/mcp
```

Os endpoints de descoberta, em conformidade com OAuth 2.1/MCP, são:

```text
https://pergunta-online.diegoramos.tech/.well-known/oauth-authorization-server
https://pergunta-online.diegoramos.tech/.well-known/oauth-protected-resource
```

O servidor fornece registro dinâmico de cliente (`/oauth/register`), autorização com conta administrativa (`/oauth/authorize`), PKCE S256, vinculação de tokens ao recurso `https://pergunta-online.diegoramos.tech/mcp`, access tokens de uma hora e refresh tokens rotativos de 30 dias (`/oauth/token`). Tokens e códigos são armazenados exclusivamente como SHA-256 no banco, em tabelas `mcp_oauth_*` criadas automaticamente na primeira utilização. Clientes somente podem registrar URLs de redirecionamento HTTPS da OpenAI/ChatGPT. A autorização exige uma conta administrativa ativa, sessão autenticada e consentimento explícito; contas normais não podem conceder acesso.

Não utilize na integração MCP o token da API REST antiga, que tem outro propósito. A documentação REST antiga expunha um token conhecido: **configure imediatamente `CHATGPT_API_TOKEN` com um segredo aleatório novo no servidor** para invalidar a chave histórica. A configuração `chatgpt_api.token` também é aceita, mas prefira variável de ambiente. Enquanto nenhum novo token for configurado, a aplicação preserva o token histórico apenas por compatibilidade, o que não é seguro em produção. Nenhuma senha, token ou segredo deve ser inserido no repositório.

## Vincular ao ChatGPT

Na versão Web do ChatGPT, abra Plugins, adicione um servidor MCP personalizado e informe a URL pública terminada em `/mcp`. Selecione a autenticação OAuth; o fluxo abre a página de login/consentimento do Pergunta.Online. Após autorizar como administrador, execute a descoberta e instale o plugin.

O servidor expõe as ferramentas `catalog`, `search`, `fetch`, `create_question`, `import_questions`, `upsert_focus`, `upsert_category`, `upsert_target` e `add_evidence`. As três primeiras exigem o escopo `questions:read`; as demais exigem `questions:write`. Todas são ligadas às rotinas já existentes de validação, auditoria, persistência e detecção de duplicidade. O acesso não concede operações genéricas de SQL, exclusão de usuários nem administração da infraestrutura.

As ferramentas de gravação só devem ser executadas com permissão do usuário e após consultar o catálogo e possíveis perguntas duplicadas. O protocolo não pesquisa notícias na Web: quem conduz a pesquisa é o cliente ChatGPT.

## Verificação

Sem autenticação, um POST JSON-RPC para `/mcp` deve retornar HTTP 401 com `WWW-Authenticate` e referência ao metadata OAuth.

Depois de autorizar, envie com `Authorization: Bearer <ACCESS_TOKEN>`:

```json
{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-06-18","capabilities":{},"clientInfo":{"name":"mcp-test","version":"1.0"}}}
```

Em seguida chame `tools/list` e `tools/call` com `{"name":"search","arguments":{"query":"Vasco"}}`. Use também o MCP Inspector no modo Streamable HTTP para testar a listagem e as operações.

O transporte é **stateless**: POST recebe resposta JSON-RPC em `application/json`; notificações válidas recebem HTTP 202. GET/SSE não é oferecido, pois o transporte Streamable HTTP permite respostas JSON para chamadas síncronas. As rotas REST existentes em `/admin/api-chatgpt/` continuam independentes.

Se a aplicação estiver atrás de proxy reverso, confirme que o cabeçalho `Authorization` chega ao PHP, que os redirects HTTPS são preservados e que `app_url` coincide exatamente com o domínio externo. O código no GitHub não atualiza automaticamente o servidor instalado; o deploy precisa publicar os novos arquivos.

## Limitações

O protocolo MCP permite consultas e gravações administrativas conforme os escopos aprovados. Esta integração não implementa um sistema de identidade federada para usuários não administrativos, nem interface MCP personalizada. O blog permanece disponível na API REST existente e não foi incluído no conjunto de ferramentas MCP.


## Cadastro histórico de usuários, perguntas e respostas

As ferramentas administrativas MCP adicionais são search_users, create_user,
update_user_profile e answer_question. create_question e import_questions agora
aceitam user_id para vincular a autoria verdadeira a uma conta existente.
É possível informar datas originais no formato YYYY-MM-DD HH:MM:SS:
users.created_at/updated_at; questions.created_at/updated_at,
waiting_since/taken_at/answered_at; question_evidence.created_at e a data da
resposta em answer_question. Datas futuras ou anteriores ao registro de origem
são rejeitadas. Sem datas explícitas, usam-se as datas normais do aplicativo.

O campo users.perfil é LONGTEXT e recebe um objeto JSON livre, por exemplo:

```json
{
  "estilo_escrita": "coloquial, mensagens curtas",
  "time_coracao": "Vasco da Gama",
  "usa_emojis": true,
  "emojis_frequentes": [":)", "⚽"],
  "abreviacoes": ["vc", "pq", "tbm"],
  "pontuacao": "muitas exclamações",
  "expressoes": ["né", "cara"],
  "exemplos_escrita": ["vc viu o jogo ontem? :)"]
}
```

A aplicação preserva o objeto JSON recebido sem reduzir sua estrutura.
Se for enviada apenas uma string, ela é armazenada na chave observacoes.
Nunca atribua características inventadas a uma pessoa; use preferências
fornecidas ou evidências autorizadas.

REST autenticada: GET/POST admin/api-chatgpt/users.php, POST
admin/api-chatgpt/user-profile.php e POST admin/api-chatgpt/answers.php.
O MCP exige o escopo OAuth users:write para criar ou consultar contas e alterar
perfis, além dos escopos questions:read / questions:write já existentes para
as outras ferramentas. É necessário renovar a autorização para conceder o
novo escopo. O administrador que executou a importação e o instante real da
operação permanecem registrados em admin_audit_log mesmo quando os dados
importados representam datas antigas.

Conta sem password explícito recebe uma senha aleatória não revelada. Não
é possível entrar nessa conta até um administrador definir uma senha pela UI.
Contas com e-mail existente não são sobrescritas por create_user; use
update_user_profile explicitamente. A resposta existente não é substituída
sem replace_existing=true.
