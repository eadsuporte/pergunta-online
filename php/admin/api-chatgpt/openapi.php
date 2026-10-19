<?php

declare(strict_types=1);

require_once __DIR__ . '/common.php';
api_method(['GET']);

$root = api_root_url();
if ($root === '') {
    $root = 'https://SEU-DOMINIO.EXEMPLO';
}
$server = rtrim($root, '/') . '/admin/api-chatgpt';

$evidenceSchema = [
    'type' => 'object',
    'required' => ['title', 'type'],
    'properties' => [
        'title' => ['type' => 'string'],
        'type' => ['type' => 'string', 'enum' => ['url', 'text']],
        'url' => ['type' => 'string', 'format' => 'uri'],
        'text' => ['type' => 'string'],
        'created_at' => ['type' => 'string', 'description' => 'YYYY-MM-DD HH:MM:SS (data original).'],
    ],
];

$questionSchema = [
    'type' => 'object',
    'required' => ['title', 'body', 'focus', 'category', 'target'],
    'properties' => [
        'title' => ['type' => 'string', 'minLength' => 15],
        'body' => ['type' => 'string', 'minLength' => 20],
        'focus' => [
            'type' => 'object',
            'required' => ['name', 'abbr'],
            'properties' => [
                'name' => ['type' => 'string'],
                'abbr' => ['type' => 'string', 'maxLength' => 12],
            ],
        ],
        'category' => ['oneOf' => [['type' => 'string'], ['type' => 'object']]],
        'target' => ['oneOf' => [['type' => 'string'], ['type' => 'object']]],
        'status' => ['type' => 'string', 'enum' => ['open', 'waiting', 'taken', 'answered', 'archived']],
        'published' => ['type' => 'boolean'],
        'moderation_status' => ['type' => 'string', 'enum' => ['pending', 'approved', 'rejected']],
        'answer_text' => ['type' => 'string'],
        'user_id' => ['type' => 'integer', 'minimum' => 1],
        'created_at' => ['type' => 'string', 'description' => 'Data original da pergunta: YYYY-MM-DD HH:MM:SS.'],
        'updated_at' => ['type' => 'string'],
        'waiting_since' => ['type' => 'string'],
        'taken_at' => ['type' => 'string'],
        'taken_by_user_id' => ['type' => 'integer', 'minimum' => 1],
        'answered_at' => ['type' => 'string'],
        'answered_by_user_id' => ['type' => 'integer', 'minimum' => 1],
        'evidence' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/Evidence']],
    ],
];

$userSchema = [
    'type' => 'object',
    'required' => ['name', 'email'],
    'properties' => [
        'name' => ['type' => 'string', 'minLength' => 2, 'maxLength' => 120],
        'email' => ['type' => 'string', 'format' => 'email'],
        'role' => ['type' => 'string', 'enum' => ['user', 'respondent']],
        'active' => ['type' => 'boolean'],
        'password' => ['type' => 'string', 'minLength' => 10],
        'perfil' => ['oneOf' => [['type' => 'object'], ['type' => 'string']]],
        'created_at' => ['type' => 'string'],
        'updated_at' => ['type' => 'string'],
    ],
];
$answerSchema = [
    'type' => 'object',
    'required' => ['question_id', 'answer_text'],
    'properties' => [
        'question_id' => ['type' => 'integer', 'minimum' => 1],
        'answer_text' => ['type' => 'string', 'minLength' => 1],
        'answered_by_user_id' => ['type' => 'integer', 'minimum' => 1],
        'answered_at' => ['type' => 'string'],
        'updated_at' => ['type' => 'string'],
        'replace_existing' => ['type' => 'boolean'],
    ],
];

$blogSourceSchema = [
    'type' => 'object',
    'required' => ['url'],
    'properties' => [
        'title' => ['type' => 'string', 'maxLength' => 255],
        'url' => ['type' => 'string', 'format' => 'uri'],
    ],
];

$blogImageSchema = [
    'type' => 'object',
    'properties' => [
        'url' => [
            'type' => 'string',
            'format' => 'uri',
            'description' => 'Imagem remota que será baixada e armazenada localmente.',
        ],
        'alt' => ['type' => 'string', 'maxLength' => 255],
        'credit' => ['type' => 'string', 'maxLength' => 255],
        'source_url' => ['type' => 'string', 'format' => 'uri'],
    ],
];

$blogPostSchema = [
    'type' => 'object',
    'required' => ['title', 'content'],
    'properties' => [
        'external_id' => [
            'type' => 'string',
            'maxLength' => 190,
            'description' => 'Identificador idempotente do sistema que envia o post.',
        ],
        'source_url' => [
            'type' => 'string',
            'format' => 'uri',
            'description' => 'URL principal que originou o artigo.',
        ],
        'title' => ['type' => 'string', 'minLength' => 3, 'maxLength' => 255],
        'slug' => ['type' => 'string', 'maxLength' => 190],
        'excerpt' => ['type' => 'string'],
        'content' => ['type' => 'string', 'minLength' => 1],
        'category' => ['type' => 'string', 'maxLength' => 120],
        'tags' => [
            'type' => 'array',
            'maxItems' => 20,
            'items' => ['type' => 'string', 'minLength' => 2, 'maxLength' => 120],
        ],
        'sources' => [
            'type' => 'array',
            'maxItems' => 20,
            'items' => ['$ref' => '#/components/schemas/BlogSource'],
        ],
        'status' => [
            'type' => 'string',
            'enum' => ['draft', 'scheduled', 'published'],
            'default' => 'published',
        ],
        'published' => [
            'type' => 'boolean',
            'description' => 'Compatibilidade com clientes antigos. Prefira status.',
        ],
        'published_at' => ['type' => 'string', 'format' => 'date-time'],
        'author_user_id' => ['type' => 'integer', 'minimum' => 1],
        'created_at' => ['type' => 'string', 'description' => 'YYYY-MM-DD HH:MM:SS, data histórica original'],
        'updated_at' => ['type' => 'string', 'description' => 'YYYY-MM-DD HH:MM:SS, data histórica de alteração'],
        'language' => ['type' => 'string', 'default' => 'pt_BR'],
        'image' => ['$ref' => '#/components/schemas/BlogImage'],
        'image_url' => [
            'type' => 'string',
            'format' => 'uri',
            'description' => 'Compatibilidade com clientes antigos. Prefira image.url.',
        ],
        'remove_image' => ['type' => 'boolean', 'default' => false],
        'meta_title' => ['type' => 'string', 'maxLength' => 255],
        'meta_description' => ['type' => 'string', 'maxLength' => 500],
        'canonical_url' => ['type' => 'string', 'format' => 'uri'],
        'update_existing' => [
            'type' => 'boolean',
            'default' => false,
            'description' => 'Atualiza o post encontrado por external_id, source_url ou fallback de título.',
        ],
        'dry_run' => [
            'type' => 'boolean',
            'default' => false,
            'description' => 'Valida dados e imagem sem persistir alterações.',
        ],
    ],
];

$blogBatchSchema = [
    'type' => 'object',
    'required' => ['items'],
    'properties' => [
        'items' => [
            'type' => 'array',
            'maxItems' => 10,
            'items' => ['$ref' => '#/components/schemas/BlogPost'],
        ],
    ],
];

$batchSchema = [
    'type' => 'object',
    'required' => ['items'],
    'properties' => [
        'items' => [
            'type' => 'array',
            'maxItems' => 50,
            'items' => ['$ref' => '#/components/schemas/Question'],
        ],
    ],
];

api_json([
    'openapi' => '3.1.0',
    'info' => [
        'title' => 'Pergunta.Online ChatGPT API',
        'version' => '1.4.0',
        'description' => 'API para pesquisa assistida por ChatGPT e cadastro controlado de focos, categorias, destinatários, perguntas e evidências.',
    ],
    'servers' => [['url' => $server]],
    'components' => [
        'securitySchemes' => [
            'bearerAuth' => [
                'type' => 'http',
                'scheme' => 'bearer',
                'bearerFormat' => 'API token',
            ],
        ],
        'schemas' => [
            'Evidence' => $evidenceSchema,
            'Question' => $questionSchema,
            'User' => $userSchema,
            'Answer' => $answerSchema,
            'BlogSource' => $blogSourceSchema,
            'BlogImage' => $blogImageSchema,
            'BlogPost' => $blogPostSchema,
            'Vote' => [
                'type' => 'object',
                'required' => ['question_id', 'user_id'],
                'properties' => [
                    'question_id' => ['type' => 'integer', 'minimum' => 1],
                    'user_id' => ['type' => 'integer', 'minimum' => 1],
                    'created_at' => ['type' => 'string'],
                ],
            ],
            'Comment' => [
                'type' => 'object',
                'required' => ['question_id', 'user_id', 'body'],
                'properties' => [
                    'question_id' => ['type' => 'integer', 'minimum' => 1],
                    'user_id' => ['type' => 'integer', 'minimum' => 1],
                    'body' => ['type' => 'string', 'minLength' => 2],
                    'created_at' => ['type' => 'string'],
                    'external_id' => ['type' => 'string'],
                ],
            ],
        ],
    ],
    'security' => [['bearerAuth' => []]],
    'paths' => [
        '/users' => [
            'get' => [
                'operationId' => 'searchUsers',
                'summary' => 'Procura usuários por nome ou e-mail e retorna perfil de escrita.',
                'parameters' => [[
                    'name' => 'q', 'in' => 'query', 'required' => false,
                    'schema' => ['type' => 'string'],
                ]],
                'responses' => ['200' => ['description' => 'Usuários localizados']],
            ],
            'post' => [
                'operationId' => 'createUser',
                'summary' => 'Cria uma conta com perfil de escrita e data de cadastro histórica.',
                'requestBody' => ['required' => true, 'content' => [
                    'application/json' => ['schema' => ['$ref' => '#/components/schemas/User']],
                ]],
                'responses' => ['200' => ['description' => 'Conta criada ou reutilizada']],
            ],
        ],
        '/user-profile' => [
            'post' => [
                'operationId' => 'updateUserProfile',
                'summary' => 'Atualiza o perfil textual/JSON de um usuário por ID.',
                'requestBody' => ['required' => true, 'content' => ['application/json' => [
                    'schema' => ['type' => 'object', 'required' => ['user_id', 'perfil'], 'properties' => [
                        'user_id' => ['type' => 'integer'],
                        'perfil' => ['oneOf' => [['type' => 'object'], ['type' => 'string']]],
                        'updated_at' => ['type' => 'string'],
                    ]],
                ]]],
                'responses' => ['200' => ['description' => 'Perfil atualizado']],
            ],
        ],
        '/answers' => [
            'post' => [
                'operationId' => 'answerQuestion',
                'summary' => 'Registra resposta, autor da resposta e data histórica.',
                'requestBody' => ['required' => true, 'content' => ['application/json' => [
                    'schema' => ['$ref' => '#/components/schemas/Answer'],
                ]]],
                'responses' => ['200' => ['description' => 'Resposta registrada']],
            ],
        ],
        '/catalog' => [
            'get' => [
                'operationId' => 'getCatalog',
                'summary' => 'Lista focos, categorias e destinatários existentes.',
                'responses' => ['200' => ['description' => 'Catálogo atual']],
            ],
        ],
        '/search' => [
            'get' => [
                'operationId' => 'searchQuestions',
                'summary' => 'Procura perguntas existentes antes de cadastrar uma nova.',
                'parameters' => [[
                    'name' => 'q',
                    'in' => 'query',
                    'required' => true,
                    'schema' => ['type' => 'string', 'minLength' => 3],
                ]],
                'responses' => ['200' => ['description' => 'Perguntas encontradas']],
            ],
        ],
        '/questions' => [
            'post' => [
                'operationId' => 'createQuestions',
                'summary' => 'Cria uma ou mais perguntas e cria ou reutiliza foco, categoria e destinatário.',
                'requestBody' => [
                    'required' => true,
                    'content' => [
                        'application/json' => [
                            'schema' => [
                                'oneOf' => [
                                    ['$ref' => '#/components/schemas/Question'],
                                    $batchSchema,
                                ],
                            ],
                        ],
                    ],
                ],
                'responses' => ['200' => ['description' => 'Resultado do cadastro']],
            ],
        ],
        '/votes' => [
            'post' => [
                'operationId' => 'importVotes',
                'summary' => 'Registra votos associados a usuários e com datas históricas, em lote de até 50.',
                'requestBody' => ['required' => true, 'content' => ['application/json' => [
                    'schema' => ['oneOf' => [
                        ['$ref' => '#/components/schemas/Vote'],
                        ['type' => 'object', 'required' => ['items'],
                            'properties' => ['items' => ['type' => 'array', 'minItems' => 1,
                                'maxItems' => 50, 'items' => ['$ref' => '#/components/schemas/Vote']]]],
                    ]],
                ]]],
                'responses' => ['200' => ['description' => 'Votos novos ou reutilizados']],
            ],
        ],
        '/comments' => [
            'post' => [
                'operationId' => 'importComments',
                'summary' => 'Cria comentários atribuídos a usuários com data retroativa.',
                'requestBody' => ['required' => true, 'content' => ['application/json' => [
                    'schema' => ['oneOf' => [
                        ['$ref' => '#/components/schemas/Comment'],
                        ['type' => 'object', 'required' => ['items'],
                            'properties' => ['items' => ['type' => 'array', 'minItems' => 1,
                                'maxItems' => 50, 'items' => ['$ref' => '#/components/schemas/Comment']]]],
                    ]],
                ]]],
                'responses' => ['200' => ['description' => 'Comentários criados ou reutilizados']],
            ],
        ],
        '/blog' => [
            'post' => [
                'operationId' => 'createBlogPosts',
                'summary' => 'Cria ou atualiza posts idempotentes, baixa imagens, grava tags e fontes.',
                'requestBody' => [
                    'required' => true,
                    'content' => [
                        'application/json' => [
                            'schema' => [
                                'oneOf' => [
                                    ['$ref' => '#/components/schemas/BlogPost'],
                                    $blogBatchSchema,
                                ],
                            ],
                        ],
                    ],
                ],
                'responses' => [
                    '200' => ['description' => 'Posts criados, atualizados, reutilizados ou apenas validados'],
                    '422' => ['description' => 'Dados inválidos ou imagem recusada'],
                ],
            ],
        ],
        '/import' => [
            'post' => [
                'operationId' => 'importResearch',
                'summary' => 'Importa em lote perguntas pesquisadas na web com suas fontes.',
                'requestBody' => [
                    'required' => true,
                    'content' => [
                        'application/json' => ['schema' => $batchSchema],
                    ],
                ],
                'responses' => ['200' => ['description' => 'Resultado da importação']],
            ],
        ],
    ],
]);
