# Pergunta.Online PHP

Versão funcional em PHP + MySQL do Pergunta.Online. Não usa Vinext, React, Node ou build frontend. A camada de apresentação usa templates Mustache em `templates/`, enquanto os arquivos PHP ficam responsáveis por validação, consultas e preparação do contexto.

## Requisitos

- PHP 8.1 ou superior
- MySQL 8.0+ ou MariaDB compatível
- extensão `pdo_mysql`
- Apache ou Nginx apontando o document root para esta pasta, ou para uma pasta que a contenha

Não é necessário Composer para renderizar os templates; o projeto inclui um renderer Mustache pequeno, suficiente para variáveis, escaping, seções, seções invertidas e partials usados pela aplicação.

## Estrutura de views

- `templates/partials/`: cabeçalho, rodapé e estruturas compartilhadas;
- `templates/admin/`: telas administrativas;
- `templates/*.mustache`: páginas públicas, autenticação, instalação e formulário de pergunta;
- `includes/layout.php`: prepara o contexto comum e chama o renderer;
- `includes/mustache.php`: renderer dos arquivos `.mustache`.

Os valores `{{variavel}}` são escapados por padrão. HTML pré-sanitizado usa `{{{variavel}}}` somente nos poucos pontos em que é necessário preservar quebras de linha.

## Configuração

Edite diretamente o arquivo `config.php`:

```php
return [
    'database' => [
        'host' => '127.0.0.1',
        'port' => 3306,
        'name' => 'pergunta_online',
        'user' => 'pergunta_online',
        'password' => 'troque-esta-senha',
    ],
    'app_url' => '',
];
```

`app_url` pode ficar vazio para detectar automaticamente o domínio e o subdiretório. Em produção, altere a senha diretamente no `config.php` do servidor e não faça commit da credencial real no repositório público.

Depois acesse `/install`. O instalador cria as tabelas e pede o primeiro administrador. Depois disso, novos usuários podem se cadastrar em `/register` e recebem sempre o perfil `user`.

## Funcionalidades

- home pública com busca, focos, assuntos e estados da pergunta;
- cadastro público configurável e criação manual de usuários pelo administrador;
- login com `password_hash()`/`password_verify()`, sessão regenerada, último acesso e limitação básica de tentativas;
- criação de perguntas usando focos, categorias e destinatários administrados, evitando variações de texto;
- moderação opcional antes da publicação;
- endosso “Boa!” e perguntas salvas, persistidos no MySQL;
- comentários públicos em perguntas publicadas, inclusive para visitantes sem cadastro, com identificação dos usuários logados, proteção contra spam e remoção administrativa;
- cronômetro de silêncio com períodos reiniciados corretamente quando a pergunta volta a aguardar resposta;
- papel `respondent` vinculado a destinatários específicos, além de `user` e `admin`;
- fluxo para respondente assumir a pauta e registrar a resposta oficial;
- linha do tempo imutável da pergunta e auditoria global das ações administrativas;
- evidências por URL, texto, documento, imagem ou vídeo, com arquivos servidos por rota autorizada;
- denúncias comunitárias com fila de análise;
- identificação de perguntas duplicadas sem quebrar a URL antiga;
- exclusão lógica e restauração de perguntas;
- administração de focos, categorias, destinatários e contas respondentes;
- administração detalhada de usuários com perguntas, endossos, salvos e redefinição de senha;
- administração detalhada de perguntas com conteúdo, estado, publicação, resposta, evidências, engajamento e histórico;
- busca administrativa com FULLTEXT, paginação e navegação da lixeira;
- dashboard operacional com moderação pendente, denúncias, pautas paradas e respostas sem evidência;
- configurações persistidas para cadastro, publicação automática, prazo de silêncio, paginação e nome do site;
- CSRF em ações POST, consultas preparadas via PDO e escaping padrão nos templates Mustache;
- migrações incrementais para atualizar instalações existentes;
- GitHub Actions com lint de PHP e smoke test do schema/bootstrap contra MySQL.

## Segurança e produção

O `config.php` bloqueia execução direta e o `.htaccess` também nega acesso ao arquivo no Apache. Em Nginx, o arquivo PHP continua sendo executado normalmente e não imprime as configurações, mas recomenda-se manter a configuração do servidor impedindo a entrega de código-fonte PHP. Use HTTPS em produção para que o cookie de sessão seja marcado como `Secure`.
