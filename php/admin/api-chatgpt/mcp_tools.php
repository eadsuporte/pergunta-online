<?php

declare(strict_types=1);

/**
 * Pure MCP tool declarations, shared by the HTTP endpoint and CLI contract tests.
 * Keeping the catalog independent from bootstrap/database makes discovery testable.
 */
const MCP_SERVER_VERSION = '1.3.0';

function mcp_tool(string $name, string $description, array $schema, bool $readonly): array {
    return [
        "name" => $name,
        "description" => $description,
        "inputSchema" => $schema,
        "annotations" => [
            "title" => str_replace("_", " ", $name),
            "readOnlyHint" => $readonly,
            "destructiveHint" => false,
            "idempotentHint" => $readonly,
            "openWorldHint" => false,
        ],
    ];
}

function mcp_tools(): array {
    $text = ["type" => "string"];
    $evidence = [
        "type" => "object",
        "required" => ["title", "type"],
        "properties" => [
            "title" => ["type" => "string", "minLength" => 2],
            "type" => ["type" => "string", "enum" => ["url", "text"]],
            "url" => $text, "text" => $text, "created_at" => $text,
            "user_id" => ["type" => "integer", "minimum" => 1],
        ],
    ];
    $question = [
        "type" => "object",
        "required" => ["title", "body", "focus", "category", "target"],
        "properties" => [
            "title" => ["type" => "string", "minLength" => 15, "maxLength" => 255],
            "body" => ["type" => "string", "minLength" => 20],
            "focus" => [
                "type" => "object", "required" => ["name", "abbr"],
                "properties" => [
                    "name" => $text,
                    "abbr" => ["type" => "string", "maxLength" => 12],
                ],
            ],
            "category" => ["oneOf" => [
                ["type" => "string"],
                ["type" => "object", "required" => ["name"], "properties" => ["name" => $text]],
            ]],
            "target" => ["oneOf" => [
                ["type" => "string"],
                ["type" => "object", "required" => ["name"], "properties" => [
                    "name" => $text, "description" => $text, "website" => $text, "contact_email" => $text,
                ]],
            ]],
            "status" => ["type" => "string", "enum" => ["open", "waiting", "taken", "answered", "archived"]],
            "published" => ["type" => "boolean"],
            "moderation_status" => ["type" => "string", "enum" => ["pending", "approved", "rejected"]],
            "answer_text" => $text,
            "user_id" => ["type" => "integer", "minimum" => 1],
            "created_at" => $text, "updated_at" => $text,
            "waiting_since" => $text, "taken_at" => $text,
            "taken_by_user_id" => ["type" => "integer", "minimum" => 1],
            "answered_at" => $text,
            "answered_by_user_id" => ["type" => "integer", "minimum" => 1],
            "evidence" => ["type" => "array", "maxItems" => 20, "items" => $evidence],
        ],
    ];
    return [
        mcp_tool("search_users", "Search administrative user records by name or email to obtain IDs and writing profiles.", [
            "type" => "object", "properties" => [
                "query" => ["type" => "string", "maxLength" => 190],
            ],
        ], true),
        mcp_tool("create_user", "Create a user or respondent with a historical registration date and a structured writing profile; an existing email is reused without modifying it.", [
            "type" => "object", "required" => ["name", "email"], "properties" => [
                "name" => ["type" => "string", "minLength" => 2, "maxLength" => 120],
                "email" => ["type" => "string", "format" => "email"],
                "role" => ["type" => "string", "enum" => ["user", "respondent"]],
                "active" => ["type" => "boolean"],
                "password" => ["type" => "string", "minLength" => 10],
                "perfil" => ["oneOf" => [["type" => "object"], ["type" => "string"]]],
                "created_at" => ["type" => "string"],
                "updated_at" => ["type" => "string"],
            ],
        ], false),
        mcp_tool("update_user_profile", "Replace the structured writing profile of an existing user without changing the account role.", [
            "type" => "object", "required" => ["user_id", "perfil"], "properties" => [
                "user_id" => ["type" => "integer", "minimum" => 1],
                "perfil" => ["oneOf" => [["type" => "object"], ["type" => "string"]]],
                "updated_at" => ["type" => "string"],
            ],
        ], false),
        mcp_tool("answer_question", "Record an answer attributed to a respondent or administrator with an optional historical date. Preserves the real administrative audit timestamp.", [
            "type" => "object", "required" => ["question_id", "answer_text"], "properties" => [
                "question_id" => ["type" => "integer", "minimum" => 1],
                "answer_text" => ["type" => "string", "minLength" => 1],
                "answered_by_user_id" => ["type" => "integer", "minimum" => 1],
                "answered_at" => ["type" => "string"],
                "updated_at" => ["type" => "string"],
                "replace_existing" => ["type" => "boolean"],
            ],
        ], false),

        mcp_tool("create_blog_post", "Create or update one real blog post with optional historical author, creation and publication dates. Use dry_run=true for a preview; never fabricate sources.", [
            "type" => "object", "required" => ["title", "content"], "properties" => [
                "title" => ["type" => "string", "minLength" => 3, "maxLength" => 255],
                "content" => ["type" => "string", "minLength" => 1],
                "author_user_id" => ["type" => "integer", "minimum" => 1],
                "created_at" => $text, "updated_at" => $text, "published_at" => $text,
                "status" => ["type" => "string", "enum" => ["draft", "scheduled", "published"]],
                "published" => ["type" => "boolean"], "external_id" => $text,
                "source_url" => $text, "category" => $text,
                "excerpt" => $text, "slug" => $text,
                "language" => $text, "meta_title" => $text, "meta_description" => $text,
                "canonical_url" => $text,
                "tags" => ["type" => "array", "maxItems" => 20, "items" => $text],
                "sources" => ["type" => "array", "maxItems" => 20,
                    "items" => ["type" => "object", "required" => ["url"],
                        "properties" => ["title" => $text, "url" => $text]]],
                "image" => ["type" => "object", "properties" => [
                    "url" => $text, "alt" => $text, "credit" => $text, "source_url" => $text]],
                "image_url" => $text, "remove_image" => ["type" => "boolean"],
                "update_existing" => ["type" => "boolean"], "dry_run" => ["type" => "boolean"],
            ],
        ], false),
        mcp_tool("import_blog_posts", "Import 1 to 10 historically dated blog posts using the same fields as create_blog_post.", [
            "type" => "object", "required" => ["items"], "properties" => [
                "items" => ["type" => "array", "minItems" => 1, "maxItems" => 10,
                    "items" => ["type" => "object", "required" => ["title", "content"],
                        "properties" => [
                            "title" => ["type" => "string", "minLength" => 3],
                            "content" => ["type" => "string", "minLength" => 1],
                            "author_user_id" => ["type" => "integer", "minimum" => 1],
                            "created_at" => $text, "updated_at" => $text,
                            "published_at" => $text, "status" => $text,
                            "external_id" => $text, "category" => $text,
                            "source_url" => $text, "tags" => ["type" => "array", "items" => $text],
                            "sources" => ["type" => "array", "items" => ["type" => "object",
                                "required" => ["url"], "properties" => ["url" => $text, "title" => $text]]],
                            "published" => ["type" => "boolean"],
                            "update_existing" => ["type" => "boolean"],
                            "dry_run" => ["type" => "boolean"],
                        ]]],
            ],
        ], false),
        mcp_tool("vote_question", "Register one vote by a real user on a public question, optionally with a historical date. Repeated votes are reused.", [
            "type" => "object", "required" => ["question_id", "user_id"], "properties" => [
                "question_id" => ["type" => "integer", "minimum" => 1],
                "user_id" => ["type" => "integer", "minimum" => 1],
                "created_at" => $text,
            ],
        ], false),
        mcp_tool("import_votes", "Import 1 to 50 dated user votes in one transaction; user/question pairs remain unique.", [
            "type" => "object", "required" => ["items"], "properties" => [
                "items" => ["type" => "array", "minItems" => 1, "maxItems" => 50,
                    "items" => ["type" => "object", "required" => ["question_id", "user_id"],
                        "properties" => [
                            "question_id" => ["type" => "integer", "minimum" => 1],
                            "user_id" => ["type" => "integer", "minimum" => 1],
                            "created_at" => $text,
                        ]]],
            ],
        ], false),
        mcp_tool("add_comment", "Create one public comment attributed to a user with an optional historical date and idempotent external_id.", [
            "type" => "object", "required" => ["question_id", "user_id", "body"], "properties" => [
                "question_id" => ["type" => "integer", "minimum" => 1],
                "user_id" => ["type" => "integer", "minimum" => 1],
                "body" => ["type" => "string", "minLength" => 2, "maxLength" => 2000],
                "created_at" => $text, "external_id" => $text,
            ],
        ], false),
        mcp_tool("catalog", "List existing focuses, categories and targets before creating data. Includes inactive entries only when requested.", [
            "type" => "object", "properties" => ["include_inactive" => ["type" => "boolean", "default" => false]],
        ], true),
        mcp_tool("search", "Search existing questions by title, text, focus, category or target. Use this before creating a question to avoid duplicates. Returns source URLs.", [
            "type" => "object", "required" => ["query"], "properties" => [
                "query" => ["type" => "string", "minLength" => 3],
            ],
        ], true),
        mcp_tool("fetch", "Fetch a question and its evidence using the id returned by search, e.g. question:123.", [
            "type" => "object", "required" => ["id"], "properties" => ["id" => $text],
        ], true),
        mcp_tool("create_question", "Create one sourced, answerable public question. Existing title + target combinations are reused. Check search first, and do not invent evidence.", $question, false),
        mcp_tool("import_questions", "Create up to 50 independently verified questions in one transaction. Each question uses the same schema as create_question.", [
            "type" => "object", "required" => ["items"], "properties" => [
                "items" => ["type" => "array", "minItems" => 1, "maxItems" => 50, "items" => $question],
            ],
        ], false),
        mcp_tool("upsert_focus", "Create or update a focus, preserving the canonical focus name and abbreviation.", [
            "type" => "object", "required" => ["name", "abbr"], "properties" => [
                "name" => $text, "abbr" => $text, "active" => ["type" => "boolean"],
            ],
        ], false),
        mcp_tool("upsert_category", "Create or update a question category (including historical registration dates).", [
            "type" => "object", "required" => ["name"], "properties" => [
                "name" => $text, "active" => ["type" => "boolean"],
                "created_at" => $text, "updated_at" => $text,
            ],
        ], false),
        mcp_tool("upsert_target", "Create or update a person or organization that can respond to a question.", [
            "type" => "object", "required" => ["name"], "properties" => [
                "name" => $text, "description" => $text, "website" => $text,
                "contact_email" => $text, "active" => ["type" => "boolean"],
            ],
        ], false),
        mcp_tool("add_evidence", "Attach a verified URL or textual evidence to an existing question. Duplicate evidence is reused.", [
            "type" => "object", "required" => ["question_id", "items"], "properties" => [
                "question_id" => ["type" => "integer", "minimum" => 1],
                "items" => ["type" => "array", "minItems" => 1, "maxItems" => 50, "items" => $evidence],
            ],
        ], false),
    ];
}

