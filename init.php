<?php
/**
 * AI Summary Plugin v7.2
 * Strategy: System Hook Interception (Total RPC Bypass)
 * * fixes: E_UNKNOWN_METHOD, App.post missing
 */
class ai_summary extends Plugin {
    private $host;
    private $feed_cache = [];

    public function init($host) {
        $this->host = $host;

        // --- 核心：全业务生命周期拦截 ---
        // 只要 POST 请求中包含 'ai_summary_op'，就在插件初始化阶段直接处理并退出。
        // 这完全绕过了 TT-RSS 的 PluginHandler 路由机制。
        if (isset($_REQUEST['ai_summary_op'])) {
            // 1. 全局权限校验
            if (empty($_SESSION['uid'])) {
                header("Content-Type: application/json");
                print json_encode(["status" => "error", "message" => "Not logged in"]);
                exit;
            }

            // 2. 路由分发
            $op = $_REQUEST['ai_summary_op'];
            header("Content-Type: application/json");

            switch ($op) {
                case 'save_prefs':
                    $this->handle_save_prefs();
                    break;
                case 'manual_summarize':
                    $this->handle_manual_summarize();
                    break;
                case 'batch_summarize':
                    $this->handle_batch_summarize();
                    break;
                case 'mark_read':
                    $this->handle_mark_read();
                    break;
                default:
                    print json_encode(["status" => "error", "message" => "Unknown Operation: $op"]);
                    break;
            }
            
            // 3. 强制终止，防止 TT-RSS 继续输出其他内容
            exit;
        }

        // 注册常规钩子
        $host->add_hook($host::HOOK_ARTICLE_BUTTON, $this);
        $host->add_hook($host::HOOK_PREFS_TAB, $this);
        $host->add_hook($host::HOOK_ARTICLE_FILTER, $this);
    }

    public function api_version() { 
        return 2; 
    }

    // --- RPC 注册表为空 ---
    // 因为我们自行接管了路由，不需要向系统注册 RPC 方法
    public function get_rpc_handlers() {
        return [];
    }

    public function about() {
        return array(
            7.2, 
            "Integrates Aliyun Qwen (System Hook Mode)",
            "YourName",
            false
        );
    }

    public function get_js() {
        $file = dirname(__FILE__) . "/init.js";
        if (file_exists($file)) return file_get_contents($file);
        return "";
    }

    public function get_css() {
        return "
            .ai-summary-box { background: #f0f4f8; border-left: 4px solid #3b82f6; padding: 10px 15px; margin-bottom: 15px; font-family: sans-serif; border-radius: 4px; }
            .ai-summary-title { font-weight: bold; color: #1e40af; margin-bottom: 5px; display: flex; align-items: center; gap: 5px; }
            .ai-summary-content { color: #334155; line-height: 1.5; font-size: 0.95em; }
            body.ttrss_dark .ai-summary-box { background: #1e293b; border-left-color: #60a5fa; }
            body.ttrss_dark .ai-summary-title { color: #60a5fa; }
            body.ttrss_dark .ai-summary-content { color: #e2e8f0; }
            body.ai-view-disabled .ai-summary-box { display: none !important; }
            .ai-batch-report h2 { color: #3b82f6; border-bottom: 1px solid #ddd; padding-bottom: 5px; margin-top: 0; }
            .ai-batch-report h3 { margin-top: 15px; margin-bottom: 5px; }
            .ai-progress-container { width: 100%; background-color: #e5e7eb; border-radius: 9999px; height: 12px; overflow: hidden; margin-top: 15px; }
            .ai-progress-bar { height: 100%; background-color: #3b82f6; width: 0%; transition: width 0.3s ease; background-image: linear-gradient(45deg,rgba(255,255,255,.15) 25%,transparent 25%,transparent 50%,rgba(255,255,255,.15) 50%,rgba(255,255,255,.15) 75%,transparent 75%,transparent); background-size: 1rem 1rem; animation: ai-progress-stripes 1s linear infinite; }
            @keyframes ai-progress-stripes { 0% { background-position: 1rem 0; } 100% { background-position: 0 0; } }
        ";
    }

    // --- 钩子实现 ---

    public function hook_prefs_tab($args) {
        if ($args != "prefPrefs") return;

        print "<div dojoType=\"dijit.layout.ContentPane\" title=\"AI Summary Settings\">";
        print "<h3>AI Configuration (Qwen Only)</h3>";
        print "<form dojoType=\"dijit.form.Form\">";
        
        // 前端保存逻辑：直接调用封装好的 Plugins.AI_Summary.callBackend
        print "<script type=\"dojo/method\" event=\"onSubmit\" args=\"evt\">
            evt.preventDefault();
            var params = dojo.formToObject(this.domNode);
            
            // 复用 init.js 中的逻辑，发送 ai_summary_op = 'save_prefs'
            if (Plugins.AI_Summary && Plugins.AI_Summary.callBackend) {
                Plugins.AI_Summary.callBackend('save_prefs', params, function(res) {
                    if (res.status == 'success') {
                        if (typeof Notify != 'undefined') Notify.msg('Settings Saved', true);
                        else alert('Settings Saved');
                    } else {
                        alert('Error: ' + res.message);
                    }
                }, function(err) {
                    alert('Network Error: ' + err);
                });
            } else {
                alert('Plugin JS not loaded. Please refresh.');
            }
        </script>";

        $qwen_key = $this->host->get($this, "qwen_api_key");
        $auto_cats = $this->host->get($this, "auto_summarize_cats");

        print "<label for=\"qwen_api_key\">Qwen API Key:</label> ";
        print "<input dojoType=\"dijit.form.ValidationTextBox\" id=\"qwen_api_key\" name=\"qwen_api_key\" value=\"$qwen_key\" style=\"width: 300px\" type=\"password\" placeholder=\"Required\"><br/>";
        print "<label for=\"auto_summarize_cats\">Auto-summarize Category IDs:</label> ";
        print "<input dojoType=\"dijit.form.ValidationTextBox\" id=\"auto_summarize_cats\" name=\"auto_summarize_cats\" value=\"$auto_cats\" style=\"width: 300px\" placeholder=\"e.g. 1,4,7\"><br/>";
        print "<button dojoType=\"dijit.form.Button\" type=\"submit\">Save Settings</button>";
        print "</form></div>";
    }

    public function hook_article_button($line) {
        $icon_src = "data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHZpZXdCb3g9IjAgMCAyNCAyNCIgZmlsbD0iIzNiODJmNiI+PHBhdGggZD0iTTE5IDlsMS4yNS0yLjc1TDIzIDVsLTIuNzUtMS4yNUwxOSAxbC0xLjI1IDIuNzVMMTUgNWwyLjc1IDEuMjVMMTkgOXptLTcuNS41TDkgNCA2LjUgOS41IDEgMTJsNS41IDIuNUw5IDIwbDIuNS01LjVMMTcgMTJsLTUuNS0yLjV6TTE5IDE1bC0xLjI1IDIuNzVMMTUgMTlsMi43NSAxLjI1TDE5IDE1eiIvPjwvc3ZnPg==";
        return "<img src=\"$icon_src\" style=\"cursor:pointer;vertical-align:middle;margin-left:5px;width:18px;height:18px;\" 
                title=\"Generate AI Summary\" onclick=\"Plugins.AI_Summary.summarize(".$line["id"].")\" />";
    }

    public function hook_article_filter($article) {
        if (!isset($article["feed_id"])) return $article;
        if (strpos($article["content"], "ai-summary-box") !== false) return $article;

        $auto_cats_str = $this->host->get($this, "auto_summarize_cats");
        if (empty($auto_cats_str)) return $article;
        
        static $auto_cats = null;
        if ($auto_cats === null) $auto_cats = explode(",", $auto_cats_str);

        $feed_id = $article["feed_id"];
        $cat_id = 0;

        if (isset($this->feed_cache[$feed_id])) {
            $cat_id = $this->feed_cache[$feed_id];
        } else {
            $pdo = Db::pdo();
            $sth = $pdo->prepare("SELECT cat_id FROM ttrss_feeds WHERE id = ?");
            $sth->execute([$feed_id]);
            if ($row = $sth->fetch()) {
                $cat_id = $row['cat_id'];
                $this->feed_cache[$feed_id] = $cat_id;
            }
        }
        
        if (in_array($cat_id, $auto_cats)) {
            $summary = $this->generate_summary($article["title"], $article["content"]);
            if ($this->is_valid_response($summary)) {
                $article["content"] = $this->format_summary_html($summary) . $article["content"];
            }
        }
        return $article;
    }

    // --- 内部处理函数 (Private Handlers) ---

    private function handle_save_prefs() {
        $qwen_key = $_REQUEST['qwen_api_key'] ?? "";
        $auto_cats = $_REQUEST['auto_summarize_cats'] ?? "";

        $this->host->set($this, "qwen_api_key", $qwen_key);
        $this->host->set($this, "auto_summarize_cats", $auto_cats);
        
        print json_encode(["status" => "success", "message" => "Settings saved."]);
    }

    private function handle_batch_summarize() {
        $ids_str = $_REQUEST['ids'] ?? "";
        if (empty($ids_str)) {
            print json_encode(["status" => "error", "message" => "No articles selected"]);
            return;
        }

        $ids = explode(",", $ids_str);
        $pdo = Db::pdo();
        $uid = $_SESSION['uid'];
        
        $articles_text = "";
        $count = 0;
        
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $sth = $pdo->prepare("SELECT title, content FROM ttrss_entries, ttrss_user_entries 
            WHERE ref_id = id AND id IN ($placeholders) AND owner_uid = ?");
        
        $params = array_merge($ids, [$uid]);
        $sth->execute($params);
        
        while ($row = $sth->fetch()) {
            $count++;
            $title = $row['title'];
            $clean_content = strip_tags($row['content']);
            $clean_content = mb_substr($clean_content, 0, 1500);
            $articles_text .= "文章 $count: [$title]\n内容: $clean_content\n\n----------------\n\n";
        }

        if (empty($articles_text)) {
            print json_encode(["status" => "error", "message" => "Could not retrieve content"]);
            return;
        }

        $prompt = "这里有 $count 篇 RSS 文章。请基于这些内容生成一份综合阅读报告。\n" . 
                  "要求：\n" .
                  "1. 首先给出一个“核心综述”，用一两句话概括这些文章的主要话题。\n" .
                  "2. 然后按主题或文章分别列出关键点 (Key Points)。\n" .
                  "3. 忽略广告和无关信息。\n\n" .
                  $articles_text;

        $result = $this->call_qwen($prompt, $this->host->get($this, "qwen_api_key"));

        if ($this->is_valid_response($result)) {
            $html = "<div class='ai-batch-report'>";
            $html .= "<h2>🤖 AI 综合简报 ($count 篇)</h2>";
            $html .= nl2br(htmlspecialchars($result));
            $html = preg_replace('/^### (.*?)$/m', '<h3>$1</h3>', $html);
            $html = preg_replace('/^\*\* (.*?)$/m', '<b>$1</b>', $html);
            $html = preg_replace('/\*\*(.*?)\*\*/', '<b>$1</b>', $html);
            $html .= "</div>";
            print json_encode(["status" => "success", "html" => $html]);
        } else {
            print json_encode(["status" => "error", "message" => $result]);
        }
    }

    private function handle_manual_summarize() {
        $id = (int) ($_REQUEST['id'] ?? 0);
        if (!$id) {
             print json_encode(["status" => "error", "message" => "Invalid ID"]);
             return;
        }

        $pdo = Db::pdo();
        $sth = $pdo->prepare("SELECT title, content FROM ttrss_entries, ttrss_user_entries 
            WHERE ref_id = id AND id = ? AND owner_uid = ?");
        $sth->execute([$id, $_SESSION['uid']]);
        
        if ($row = $sth->fetch()) {
            $summary = $this->generate_summary($row['title'], $row['content']);
            if ($this->is_valid_response($summary)) {
                print json_encode(["status" => "success", "html" => $this->format_summary_html($summary)]);
            } else {
                print json_encode(["status" => "error", "message" => $summary]);
            }
        } else {
            print json_encode(["status" => "error", "message" => "Article not found"]);
        }
    }

    private function handle_mark_read() {
        $ids_str = $_REQUEST['ids'] ?? "";
        if (empty($ids_str)) {
            print json_encode(["status" => "error", "message" => "No IDs provided"]);
            return;
        }
        
        $ids = explode(",", $ids_str);
        $ids = array_map('intval', $ids);
        $ids = array_filter($ids); 
        
        if (empty($ids)) {
             print json_encode(["status" => "error", "message" => "Invalid IDs"]);
             return;
        }

        $pdo = Db::pdo();
        $uid = $_SESSION['uid'];
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $sql = "UPDATE ttrss_user_entries SET unread = false, last_read = NOW() WHERE ref_id IN ($placeholders) AND owner_uid = ?";
        $params = array_merge($ids, [$uid]);
        $sth = $pdo->prepare($sql);
        $sth->execute($params);
        $count = $sth->rowCount();
        
        print json_encode(["status" => "success", "updated" => $count]);
    }

    private function generate_summary($title, $content) {
        $key = $this->host->get($this, "qwen_api_key");
        if (!$key) return "Qwen Error: API Key not configured.";
        
        $clean = mb_substr(strip_tags($content), 0, 8000);
        $prompt = "请为以下RSS文章生成一段简明扼要的中文总结（300字以内），使用要点列表形式：\n\n标题：$title\n内容：$clean";
        return $this->call_qwen($prompt, $key);
    }

    private function call_qwen($prompt, $api_key) {
        $url = "https://dashscope.aliyuncs.com/api/v1/services/aigc/text-generation/generation";
        $data = array(
            "model" => "qwen-turbo",
            "input" => array("messages" => array(
                array("role" => "system", "content" => "You are a helpful assistant summarizer."), 
                array("role" => "user", "content" => $prompt)
            ))
        );
        $headers = array("Authorization: Bearer " . $api_key);
        
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_HTTPHEADER, array_merge(array('Content-Type: application/json'), $headers));
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, 60); 
        $result = curl_exec($ch);
        curl_close($ch);
        
        $json = json_decode($result, true);
        if (isset($json['code']) && $json['code'] != "") return "Qwen Error: " . ($json['message'] ?? "Unknown error");
        return $json['output']['text'] ?? "Qwen Error: Empty response";
    }

    private function is_valid_response($text) {
        return !empty($text) && strpos($text, "Qwen Error:") !== 0;
    }

    private function format_summary_html($text) {
        $text = nl2br(htmlspecialchars($text));
        $text = preg_replace('/\*\*(.*?)\*\*/', '<b>$1</b>', $text);
        return "<div class='ai-summary-box'><div class='ai-summary-title'>✨ AI Summary</div><div class='ai-summary-content'>$text</div></div>";
    }
}