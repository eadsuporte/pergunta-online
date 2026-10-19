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

O catálogo MCP atual possui **18 ferramentas**: `search_users`, `create_user`, `update_user_profile`, `answer_question`, `create_blog_post`, `import_blog_posts`, `vote_question`, `import_votes`, `add_comment`, `catalog`, `search`, `fetch`, `create_question`, `import_questions`, `upsert_focus`, `upsert_category`, `upsert_target` e `add_evidence`. `catalog`, `search` e `fetch` exigem `questions:read`; `search_users`, `create_user` e `update_user_profile` exigem `users:write`; as demais exigem `questions:write`. Todas são ligadas às rotinas já existentes de validação, auditoria, persistência e detecção de duplicidade. O acesso não concede operações genéricas de SQL, exclusão de usuários nem administração da infraestrutura.

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

O protocolo MCP permite consultas e gravações administrativas conforme os escopos aprovados. Esta integração não implementa um sistema de identidade federada para usuários não administrativos, nem interface MCP personalizada. O blog também é oferecido por create_blog_post e import_blog_posts no MCP, com prévia dry_run opcional.


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


## Publicação de blog, votação e comentários por MCP

As novas ferramentas MCP são create_blog_post, import_blog_posts (até 10 por
transação), vote_question, import_votes (até 50) e add_comment. A ferramenta
upsert_category já existente continua cadastrando categorias de questões;
agora aceita created_at e updated_at no cadastro. Categorias do blog continuam
armazenadas no campo textual blog_posts.category.

create_blog_post aceita author_user_id, created_at, updated_at, published_at,
status, category, content, tags, sources, image, external_id, update_existing
e dry_run. Um artigo pode ser publicado retroativamente, desde que as datas
sejam consistentes. Os mecanismos existentes de sanitização HTML, download
seguro de imagem e deduplicação do blog são reutilizados. O author_user_id é
validado antes de registrar atividade.

vote_question e import_votes aceitam question_id, user_id e created_at. Cada
usuário possui no máximo um voto por questão; reenvios retornam o voto original
sem alterar sua data. add_comment recebe question_id, user_id, body,
created_at e um external_id opcional para deduplicar reenvios. Os comentários
são armazenados na mesma question_comments usada pela interface pública.

Quando um usuário é autor de uma pergunta/post, respondente, votante,
comentarista ou autor explícito de uma evidência, o serviço atualiza
users.last_login_at para a maior data de atividade registrada; jamais
retrocede uma data mais recente. Esta é uma data derivada de atividade
atribuída, não uma garantia de autenticação real. O operador administrativo
e o instante real de importação permanecem registrados separadamente
em admin_audit_log, permitindo distinguir atividade histórica importada
de ações efetivamente realizadas no servidor.

As novas operações REST estão em admin/api-chatgpt/votes.php e
admin/api-chatgpt/comments.php. O serviço de blog é compartilhado com
admin/api-chatgpt/blog.php e preserva os contratos existentes. É preciso
realizar deploy e executar a migração schema_version 11 antes do uso.


## Diagnóstico de ferramentas ausentes no ChatGPT

A lista MCP é gerada por `mcp_tools()` em `php/admin/api-chatgpt/mcp_tools.php` e entregue em `tools/list`; o endpoint não implementa uma lista reduzida para usuários antigos. O `initialize` agora anuncia a versão `1.2.0`. Se o ChatGPT só apresentar as nove ferramentas antigas, enquanto o GitHub tem 18, isso indica **deploy antigo ou catálogo em cache na conexão do ChatGPT**, não ausência de `create_user` no código.

1. Publique o conteúdo atualizado de `php/` no servidor HTTPS e execute as migrações, se houver pendentes (a função `db()` executa as migrações versionadas).
2. Execute localmente `php php/tests/mcp_tool_discovery_test.php` e `php php/tests/mcp_import_contracts_test.php`.
3. Com um token OAuth administrativo válido, teste **a instância implantada** (não imprima/commite o token):

   ```sh
   PERGUNTA_MCP_URL=https://pergunta-online.diegoramos.tech/mcp \
   PERGUNTA_MCP_TOKEN="$MCP_ACCESS_TOKEN" \
   php php/tests/mcp_live_discovery_test.php
   ```

4. Se o teste remoto retornar versão antiga ou catálogo incompleto, corrija o deploy/OPcache do PHP antes de atualizar a conexão. Se ele retornar 18 ferramentas, atualize a descoberta do plugin MCP no ChatGPT; caso a lista permaneça antiga, desconecte e conecte o servidor MCP novamente e autorize os escopos `questions:read questions:write users:write`.

Não é possível atualizar a definição de um plugin já instalado no ChatGPT apenas mudando o código no GitHub: a hospedagem precisa receber os arquivos e o cliente precisa redescobrir o catálogo. Os testes não executam gravações.
