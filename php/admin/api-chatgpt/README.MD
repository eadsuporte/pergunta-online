# Prompt diário para o ChatGPT

Todo dia, busque na WEB por notícias, comunicados, documentos, decisões, dados e fatos novos relacionados a:

Futebol brasileiro e internacional, com foco em fatos recentes que possam gerar perguntas públicas relevantes. Pesquise diariamente por combinações e variações dos seguintes termos: futebol, futebol brasileiro, Brasileirão Série A, Brasileirão Série B, Copa do Brasil, Campeonato Brasileiro, CBF, federações estaduais, CONMEBOL, Libertadores, Copa Sul-Americana, FIFA, clubes brasileiros, SAF, dirigentes de futebol, presidentes de clubes, técnicos, jogadores, seleções, Seleção Brasileira, convocação, transferências, janela de transferências, contratação, renovação de contrato, rescisão, salários, premiações, patrocínios, direitos de transmissão, contratos de TV, finanças dos clubes, dívidas, recuperação judicial, investimento em futebol, venda de jogadores, categorias de base, estádio, arena, obras em estádio, público, ingressos, torcida, segurança em estádios, arbitragem, VAR, erros de arbitragem, súmulas, punições, STJD, Tribunal Desportivo, cartões, suspensões, regulamentos, mudanças de regra, calendário, adiamento de jogos, alteração de datas, logística, viagens, lesões, departamento médico, retorno de jogadores, treinamento, desempenho esportivo, denúncias no futebol, investigação, manipulação de resultados, apostas esportivas, integridade esportiva, racismo no futebol, violência no futebol, governança, transparência de clubes, eleições de clubes, estatutos, assembleias, obras de CT, infraestrutura, futebol feminino e categorias de base.

Não faça apenas uma busca genérica por "futebol". Combine os termos acima com nomes de clubes, jogadores, dirigentes, entidades, campeonatos e acontecimentos que estejam em destaque no dia. Dê preferência a fatos publicados ou atualizados nas últimas 24 horas, sem ignorar documentos oficiais recentes que ajudem a contextualizar uma pergunta ainda sem resposta.

Cadastre automaticamente no Pergunta.Online somente os resultados que realmente gerem perguntas públicas relevantes, objetivas e verificáveis.

Use obrigatoriamente a API abaixo para consultar o que já existe, evitar duplicidades e cadastrar os novos itens.

API base:

https://pergunta-online.diegoramos.tech/admin/api-chatgpt/

Token (defina CHATGPT_API_TOKEN no servidor; nunca publique seu valor):

SEU_TOKEN_PRIVADO

Em todas as chamadas envie:

```http
Authorization: Bearer SEU_TOKEN_PRIVADO
Content-Type: application/json
```

Para testar diretamente o servidor pela porta 8080, sem depender da resolução DNS ou do proxy reverso, use:

```bash
curl -v \
  http://137.184.104.144:8080/admin/api-chatgpt/catalog.php \
  -H 'Host: pergunta-online.diegoramos.tech' \
  -H 'Authorization: Bearer SEU_TOKEN_PRIVADO' \
  -H 'Content-Type: application/json'
```

Antes de pesquisar, consulte o catálogo atual:

```
GET https://pergunta-online.diegoramos.tech/admin/api-chatgpt/catalog.php
```

O catálogo contém focos, categorias e destinatários já cadastrados. Reutilize os registros existentes sempre que representarem a mesma coisa e não crie pequenas variações de nome para entidades que já existem.

Depois pesquise a WEB usando os termos de futebol definidos acima, procurando fatos atuais, relevantes e verificáveis. Priorize fontes primárias, como sites oficiais, comunicados, documentos públicos, relatórios, decisões, portais institucionais, legislação, diários oficiais, atas, editais, dados oficiais, vídeos oficiais e publicações feitas pela própria pessoa ou organização envolvida.

Fontes jornalísticas e outras fontes independentes podem complementar o contexto, principalmente quando não existir uma fonte primária suficiente, mas não transforme opinião, boato, especulação, postagem sem comprovação ou inferência em fato.

Para cada informação encontrada, analise primeiro se realmente existe uma pergunta ainda sem resposta. Não crie uma pergunta apenas para converter uma notícia conhecida em uma pergunta artificial.

A pergunta precisa:

- ser dirigida a uma pessoa, empresa, órgão ou instituição capaz de responder;
- ser clara, específica e respondível;
- pedir uma informação, explicação, posicionamento, compromisso, prazo ou prestação de contas que ainda não esteja respondida pelas próprias fontes;
- ter contexto factual suficiente para que outra pessoa entenda por que a pergunta existe;
- usar datas absolutas quando forem relevantes;
- evitar linguagem promocional, partidária, sensacionalista ou acusatória;
- ser sustentada pelas URLs realmente consultadas.

Antes de cadastrar cada pergunta, procure possíveis duplicidades:

```
GET https://pergunta-online.diegoramos.tech/admin/api-chatgpt/search.php?q=TERMOS_DA_PERGUNTA
```

Pesquise usando os principais termos do assunto, do destinatário e da pergunta. Se já existir uma pergunta materialmente equivalente, não crie outra.

Se existir uma pergunta relacionada, mas a nova pergunta pedir uma informação diferente e concreta, ela pode ser cadastrada.

Quando identificar uma pergunta válida, monte o item neste formato:

```json
{
  "title": "Pergunta clara e específica?",
  "body": "Contexto factual explicando o que aconteceu, quando aconteceu, o que já está comprovado e qual informação ainda falta.",
  "focus": {
    "name": "Nome do foco",
    "abbr": "SIGLA"
  },
  "category": "Categoria",
  "target": {
    "name": "Pessoa, empresa, órgão ou instituição responsável",
    "description": "Descrição curta do destinatário",
    "website": "https://site-oficial.example",
    "contact_email": ""
  },
  "status": "open",
  "evidence": [
    {
      "type": "url",
      "title": "Título descritivo da fonte",
      "url": "https://fonte.example/documento"
    }
  ]
}
```

Não invente website, e-mail, nome, sigla, data ou qualquer outro dado apenas para preencher o JSON. Quando uma informação opcional não estiver disponível, deixe-a vazia ou não a envie.

Cada URL em `evidence` deve ter sido realmente aberta e utilizada para sustentar o contexto. Não envie página de busca, URL quebrada, fonte irrelevante ou fonte que não sustente nenhuma afirmação do texto.

Quando houver várias perguntas válidas, envie todas juntas, com no máximo 50 itens por chamada:

```
POST https://pergunta-online.diegoramos.tech/admin/api-chatgpt/import.php
```

Corpo:

```json
{
  "items": [
    {
      "title": "Pergunta 1?",
      "body": "Contexto da pergunta 1.",
      "focus": {
        "name": "Nome do foco",
        "abbr": "SIGLA"
      },
      "category": "Categoria",
      "target": {
        "name": "Destinatário"
      },
      "status": "open",
      "evidence": [
        {
          "type": "url",
          "title": "Fonte utilizada",
          "url": "https://fonte.example"
        }
      ]
    }
  ]
}
```

Se precisar cadastrar ou atualizar entidades separadamente, use:

```
POST https://pergunta-online.diegoramos.tech/admin/api-chatgpt/focuses.php
POST https://pergunta-online.diegoramos.tech/admin/api-chatgpt/categories.php
POST https://pergunta-online.diegoramos.tech/admin/api-chatgpt/targets.php
POST https://pergunta-online.diegoramos.tech/admin/api-chatgpt/questions.php
POST https://pergunta-online.diegoramos.tech/admin/api-chatgpt/evidences.php
```

O schema OpenAPI está disponível em:

```
GET https://pergunta-online.diegoramos.tech/admin/api-chatgpt/openapi.php
```

Ao receber a resposta da API, verifique o campo `created`.

Se `created=true`, a pergunta foi criada.

Se `created=false`, a pergunta já existia e foi reutilizada; não tente criar outra versão apenas mudando algumas palavras.

Se a API rejeitar algum item, corrija somente erros objetivos usando informação que possa ser confirmada nas fontes. Nunca invente dados para fazer a validação passar.

Repita esse processo todos os dias, variando as combinações dos termos de futebol para cobrir clubes, competições, entidades, dirigentes, jogadores, finanças, arbitragem, infraestrutura, contratos, integridade esportiva e demais assuntos relevantes.

Ao final de cada execução, apresente um resumo contendo:

- o que foi pesquisado;
- quantas fontes foram realmente consultadas;
- quantas perguntas foram descartadas porque a resposta já estava publicada;
- quantas foram descartadas por duplicidade;
- quantas foram cadastradas;
- ID, título, destinatário e URL de cada pergunta criada ou reutilizada.

## API do Blog

Para criar ou atualizar posts do blog:

```
POST https://pergunta-online.diegoramos.tech/admin/api-chatgpt/blog.php
```

O endpoint continua aceitando o formato antigo com `published` e `image_url`, mas o formato recomendado é:

```json
{
  "external_id": "vasco-ge-20260928-001",
  "source_url": "https://fonte.example/noticia",
  "title": "Vasco inicia nova etapa das obras em São Januário",
  "slug": "vasco-inicia-nova-etapa-obras-sao-januario",
  "excerpt": "Resumo curto para a listagem.",
  "content": "<p>Conteúdo completo do artigo em HTML.</p>",
  "category": "Vasco",
  "tags": [
    "Vasco",
    "São Januário",
    "Estádio"
  ],
  "sources": [
    {
      "title": "Comunicado oficial do Vasco",
      "url": "https://vasco.com.br/exemplo"
    },
    {
      "title": "Reportagem complementar",
      "url": "https://fonte.example/reportagem"
    }
  ],
  "status": "published",
  "published_at": "2026-09-28T15:00:00-03:00",
  "language": "pt_BR",
  "image": {
    "url": "https://site-publico.example/imagem.jpg",
    "alt": "São Januário durante partida do Vasco",
    "credit": "Nome da fonte",
    "source_url": "https://site-publico.example/pagina-da-imagem"
  },
  "meta_title": "Vasco inicia nova etapa das obras em São Januário",
  "meta_description": "Veja os detalhes da nova etapa das obras em São Januário.",
  "canonical_url": "https://pergunta-online.diegoramos.tech/blog/vasco-inicia-nova-etapa-obras-sao-januario",
  "update_existing": false,
  "dry_run": false
}
```

### Idempotência e atualização

Use `external_id` sempre que o sistema que envia o post tiver um identificador estável. A API procura duplicidade nesta ordem:

1. `external_id`;
2. `source_url`;
3. título, apenas como compatibilidade quando os dois anteriores não forem enviados.

Quando um registro já existe e `update_existing=false`, a API devolve `reused=true` e não altera o post.

Quando `update_existing=true`, título e conteúdo são atualizados e os campos opcionais só são alterados quando aparecem no JSON. Omitir `tags`, `sources`, `image`, SEO ou categoria preserva o valor anterior.

### Status

Prefira `status`:

- `draft`: não publicado;
- `scheduled`: exige `published_at`;
- `published`: publicado; se `published_at` estiver no futuro, a API normaliza para `scheduled`.

O campo antigo `published` continua funcionando para compatibilidade.

### Imagem

Quando `image.url` ou o campo legado `image_url` for enviado, o PHP baixa a imagem antes de abrir a transação do banco, valida MIME, dimensões e tamanho e salva em `php/upload`.

São aceitos JPG, PNG, WEBP e GIF com até 8 MB. URLs locais, privadas, reservadas, com autenticação embutida ou portas diferentes de 80/443 são recusadas. Redirecionamentos também são revalidados.

A resposta inclui MIME, tamanho, largura, altura, URL original, URL final depois dos redirecionamentos e o caminho local salvo.

### Tags e fontes

`tags` aceita até 20 textos. Elas são usadas também para melhorar a seleção de posts relacionados.

`sources` aceita até 20 objetos com `title` e `url`. As fontes ficam armazenadas separadamente e aparecem no final do artigo publicado.

### Dry run

Com:

```json
{
  "title": "Título",
  "content": "<p>Conteúdo</p>",
  "image": {
    "url": "https://site.example/imagem.jpg"
  },
  "dry_run": true
}
```

a API valida o post, testa a imagem remota e informa se criaria ou atualizaria um registro, mas não grava banco nem arquivo em `php/upload`.

### Lote

É possível enviar até 10 posts:

```json
{
  "items": [
    {
      "external_id": "post-001",
      "title": "Primeiro artigo",
      "content": "<p>Conteúdo.</p>",
      "status": "published"
    },
    {
      "external_id": "post-002",
      "title": "Segundo artigo",
      "content": "<p>Outro conteúdo.</p>",
      "status": "draft"
    }
  ]
}
```

A resposta de cada item informa `created`, `updated`, `reused` e `dry_run`.
