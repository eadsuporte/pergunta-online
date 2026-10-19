<?php

declare(strict_types=1);

require_once __DIR__ . "/common.php";
require_once dirname(__DIR__, 2) . "/includes/mcp_oauth.php";

/**
 * Sessionless MCP Streamable HTTP endpoint for ChatGPT.
 * JSON-RPC requests are answered synchronously as application/json.
 */
function mcp_rpc(mixed $id, array $payload, int $status = 200): never {
    $response = ["jsonrpc" => "2.0", "id" => $id] + $payload;
    mcp_respond($response, $status);
}

function mcp_rpc_error(mixed $id, int $code, string $message, int $status = 200): never {
    mcp_rpc($id, ["error" => ["code" => $code, "message" => $message]], $status);
}

require_once __DIR__ . "/mcp_tools.php";

function mcp_find_tool(string $name): ?array {
    foreach (mcp_tools() as $tool) {
        if ($tool["name"] === $name) {
            return $tool;
        }
    }
    return null;
}

function mcp_get_catalog(bool $inactive): array {
    $where = $inactive ? "" : " WHERE active=1";
    return [
        "focuses" => db()->query("SELECT id,name,abbr,active FROM focuses{$where} ORDER BY name")->fetchAll(),
        "categories" => db()->query("SELECT id,name,active FROM categories{$where} ORDER BY name")->fetchAll(),
        "targets" => db()->query("SELECT id,name,description,website,contact_email,active FROM targets{$where} ORDER BY name")->fetchAll(),
    ];
}

function mcp_search(string $query): array {
    $query = trim($query);
    if (strlen($query) < 3 || strlen($query) > 255) {
        throw new InvalidArgumentException("query must contain between 3 and 255 characters.");
    }
    $like = "%" . $query . "%";
    $stmt = db()->prepare("SELECT id,title,body,focus_name,category,target_name,status,published,moderation_status,created_at
        FROM questions WHERE deleted_at IS NULL
          AND (title LIKE ? OR body LIKE ? OR focus_name LIKE ? OR category LIKE ? OR target_name LIKE ?)
        ORDER BY updated_at DESC,id DESC LIMIT 20");
    $stmt->execute([$like, $like, $like, $like, $like]);
    $rows = $stmt->fetchAll();
    $root = mcp_base_url();
    return ["results" => array_map(static function(array $row) use ($root): array {
        $id = (int)$row["id"];
        return [
            "id" => "question:" . $id, "title" => $row["title"],
            "url" => $root . "/question?id=" . $id,
            "text" => $row["body"], "focus" => $row["focus_name"],
            "category" => $row["category"], "target" => $row["target_name"],
            "status" => $row["status"],
            "published" => (bool)$row["published"],
        ];
    }, $rows)];
}

function mcp_fetch(string $id): array {
    if (preg_match("/^(?:question:)?([1-9][0-9]*)$/", $id, $matches) !== 1) {
        throw new InvalidArgumentException("Invalid question id.");
    }
    $questionId = (int)$matches[1];
    $stmt = db()->prepare("SELECT id,title,body,focus_name,category,target_name,status,published,
        moderation_status,answer_text,created_at,updated_at,user_id,answered_at,answered_by FROM questions WHERE id=? AND deleted_at IS NULL");
    $stmt->execute([$questionId]);
    $row = $stmt->fetch();
    if (!$row) {
        throw new InvalidArgumentException("Question not found.");
    }
    $stmt = db()->prepare("SELECT evidence_type,title,reference_value,created_at
        FROM question_evidence WHERE question_id=? ORDER BY created_at,id LIMIT 100");
    $stmt->execute([$questionId]);
    $row["evidence"] = $stmt->fetchAll();
    $row["id"] = "question:" . $questionId;
    $row["url"] = mcp_base_url() . "/question?id=" . $questionId;
    return $row;
}

function mcp_execute_tool(string $name, array $args, array $actor): array {
    switch ($name) {
        case "search_users":
            return api_users_search((string)($args["query"] ?? ""));
        case "create_user":
            db()->beginTransaction();
            $createdUser = api_user_create($args, $actor);
            db()->commit();
            return $createdUser;
        case "update_user_profile":
            db()->beginTransaction();
            $profileResult = api_user_profile_update($args, $actor);
            db()->commit();
            return $profileResult;
        case "answer_question":
            db()->beginTransaction();
            $answerResult = api_question_answer($args, $actor);
            db()->commit();
            return $answerResult;
        case "create_blog_post":
        case "import_blog_posts":
            require_once __DIR__ . "/blog_service.php";
            $posts = $name === "create_blog_post" ? [$args] : ($args["items"] ?? []);
            if (!is_array($posts) || !array_is_list($posts)) {
                throw new InvalidArgumentException("items deve ser uma lista.");
            }
            $result = api_blog_batch($posts, $actor);
            return $name === "create_blog_post" ? $result["posts"][0] : $result;
        case "vote_question":
        case "import_votes":
            require_once __DIR__ . "/social.php";
            $items = $name === "vote_question" ? [$args] : ($args["items"] ?? []);
            if (!is_array($items) || !array_is_list($items)
                || count($items) < 1 || count($items) > 50) {
                throw new InvalidArgumentException("Informe de 1 a 50 votos.");
            }
            db()->beginTransaction();
            $votes = [];
            foreach ($items as $vote) {
                if (!is_array($vote) || array_is_list($vote)) {
                    throw new InvalidArgumentException("Cada voto deve ser um objeto.");
                }
                $votes[] = api_vote_question($vote, $actor);
            }
            db()->commit();
            return $name === "vote_question" ? $votes[0] : ["received" => count($items), "votes" => $votes];
        case "add_comment":
            require_once __DIR__ . "/social.php";
            db()->beginTransaction();
            $comment = api_comment_question($args, $actor);
            db()->commit();
            return $comment;
        case "catalog":
            return mcp_get_catalog(($args["include_inactive"] ?? false) === true);
        case "search":
            return mcp_search((string)($args["query"] ?? ""));
        case "fetch":
            return mcp_fetch((string)($args["id"] ?? ""));
        case "create_question":
        case "import_questions":
            $items = $name === "create_question" ? [$args] : ($args["items"] ?? []);
            if (!is_array($items) || !array_is_list($items) || count($items) < 1 || count($items) > 50) {
                throw new InvalidArgumentException("Provide between 1 and 50 question objects.");
            }
            $result = [];
            db()->beginTransaction();
            foreach ($items as $item) {
                if (!is_array($item) || array_is_list($item)) {
                    throw new InvalidArgumentException("Each question must be an object.");
                }
                $result[] = api_question_create($item, $actor);
            }
            db()->commit();
            return $name === "create_question" ? $result[0] : ["received" => count($items), "questions" => $result];
        case "upsert_focus":
            db()->beginTransaction();
            $r = api_focus_upsert($args, $actor);
            db()->commit();
            return $r;
        case "upsert_category":
            db()->beginTransaction();
            $r = api_category_upsert($args, $actor);
            db()->commit();
            return $r;
        case "upsert_target":
            db()->beginTransaction();
            $r = api_target_upsert($args, $actor);
            db()->commit();
            return $r;
        case "add_evidence":
            $qid = (int)($args["question_id"] ?? 0);
            $items = $args["items"] ?? [];
            if ($qid < 1 || !is_array($items) || !array_is_list($items) ||
                count($items) < 1 || count($items) > 50) {
                throw new InvalidArgumentException("Provide a valid question_id and 1 to 50 evidence objects.");
            }
            $stmt = db()->prepare("SELECT id FROM questions WHERE id=? AND deleted_at IS NULL");
            $stmt->execute([$qid]);
            if (!$stmt->fetchColumn()) {
                throw new InvalidArgumentException("Question not found.");
            }
            db()->beginTransaction();
            $evidences = [];
            foreach ($items as $item) {
                if (!is_array($item) || array_is_list($item)) {
                    throw new InvalidArgumentException("Evidence must be an object.");
                }
                $evidences[] = api_evidence_add($qid, $item, $actor);
            }
            db()->commit();
            return ["question_id" => $qid, "evidence" => $evidences];
    }
    throw new InvalidArgumentException("Unknown tool.");
}

$method = strtoupper((string)($_SERVER["REQUEST_METHOD"] ?? "GET"));
if ($method !== "POST") {
    header("Allow: POST");
    mcp_respond(["error" => "Use POST for JSON-RPC requests. GET SSE is not implemented."], 405);
}
$length = (int)($_SERVER["CONTENT_LENGTH"] ?? 0);
if ($length > 1024 * 1024) {
    mcp_respond(["error" => "Payload exceeds 1 MB."], 413);
}
$raw = file_get_contents("php://input");
if (!is_string($raw) || strlen($raw) > 1024 * 1024) {
    mcp_respond(["error" => "Payload exceeds 1 MB."], 413);
}
try {
    $request = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
} catch (JsonException $e) {
    mcp_rpc_error(null, -32700, "Parse error.");
}
if (!is_array($request) || array_is_list($request) ||
    ($request["jsonrpc"] ?? null) !== "2.0" || !is_string($request["method"] ?? null) ||
    (array_key_exists("id", $request) && !is_int($request["id"]) && !is_string($request["id"]))) {
    mcp_rpc_error(null, -32600, "Invalid request.");
}
$id = $request["id"] ?? null;
$rpcMethod = $request["method"];
$params = $request["params"] ?? [];
if (!is_array($params) || ($params !== [] && array_is_list($params))) {
    mcp_rpc_error($id, -32602, "Invalid params.");
}
if (!array_key_exists("id", $request)) {
    if ($rpcMethod === "notifications/initialized" || $rpcMethod === "notifications/cancelled") {
        http_response_code(202);
        exit;
    }
    mcp_rpc_error(null, -32600, "Unsupported notification.");
}

if ($rpcMethod === "initialize") {
    mcp_oauth_actor("questions:read");
    mcp_rpc($id, ["result" => [
        "protocolVersion" => "2025-06-18",
        "capabilities" => ["tools" => ["listChanged" => false]],
        "serverInfo" => ["name" => "pergunta-online", "version" => MCP_SERVER_VERSION],
        "instructions" => "Search for existing questions and inspect the catalog before creating anything. Preserve accurate source attribution and real administrative audit logs when importing historical dates. Authorization is required for all operations.",
    ]]);
}
if ($rpcMethod === "ping") {
    mcp_oauth_actor("questions:read");
    mcp_rpc($id, ["result" => new stdClass()]);
}
if ($rpcMethod === "tools/list") {
    mcp_oauth_actor("questions:read");
    mcp_rpc($id, ["result" => ["tools" => mcp_tools()]]);
}
if ($rpcMethod === "tools/call") {
    $name = (string)($params["name"] ?? "");
    $args = $params["arguments"] ?? [];
    if (!is_array($args) || ($args !== [] && array_is_list($args))) {
        mcp_rpc_error($id, -32602, "Tool arguments must be an object.");
    }
    $tool = mcp_find_tool($name);
    if ($tool === null) {
        mcp_rpc_error($id, -32602, "Unknown tool.");
    }
    $userTool = in_array($name, ["search_users", "create_user", "update_user_profile"], true);
    $scope = $userTool ? "users:write" : ($tool["annotations"]["readOnlyHint"] ? "questions:read" : "questions:write");
    $actor = mcp_oauth_actor($scope);
    try {
        $data = mcp_execute_tool($name, $args, $actor);
        mcp_rpc($id, ["result" => [
            "content" => [["type" => "text", "text" => json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]],
            "structuredContent" => $data,
            "isError" => false,
        ]]);
    } catch (Throwable $e) {
        if (db()->inTransaction()) {
            db()->rollBack();
        }
        $invalid = $e instanceof InvalidArgumentException;
        if (!$invalid) {
            error_log("MCP tool " . $name . ": " . $e->getMessage());
        }
        mcp_rpc($id, ["result" => [
            "content" => [["type" => "text", "text" => $invalid ? $e->getMessage() : "Internal tool error."]],
            "isError" => true,
        ]]);
    }
}
mcp_rpc_error($id, -32601, "Method not found.");
