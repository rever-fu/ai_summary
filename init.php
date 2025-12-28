<?php
class AI_Summary extends Plugin {
    private $host;

    // 插件初始化
    function init($host) {
        $this->host = $host;
        $host->add_hook($host::HOOK_ARTICLE_BUTTON, $this);
        $host->add_hook($host::HOOK_PREFS_TAB, $this);
        $host->add_hook($host::HOOK_ARTICLE_FILTER, $this);
    }

    // 插件信息
    function about() {
        return array(
            4.8, // 确保版本号对应 v4.8，包含 mark_read 接口
            "Integrates Aliyun Qwen for article summarization (Batch & Single)",
            "YourName",
            false
        );
    }

    // 前端 JS 注入
    function get_js() {
        return file_get_contents(dirname(__FILE__) . "/init.js");
    }

    // 前端 CSS 注入
    function get_css() {
        return "
            .ai-summary-box {
                background: #f0f4f8;
                border-left: 4px solid #3b82f6;
                padding: 10px 15px;
                margin-bottom: 15px;
                font-family: sans-serif;
                border-radius: 4px;
            }
            .ai-summary-title {
                font-weight: bold;
                color: #1e40af;
                margin-bottom: 5px;
                display: flex;
                align-items: center;
                gap: 5px;
            }
            .ai-summary-content {
                color: #334155;
                line-height: 1.5;
                font-size: 0.95em;
            }
            body.ai-view-disabled .ai-summary-box {
                display: none !important;
            }
            body.ttrss_dark .ai-summary-box {
                background: #1e293b;
                border-left-color: #60a5fa;
            }
            body.ttrss_dark .ai-summary-title {
                color: #60a5fa;
            }
            body.ttrss_dark .ai-summary-content {
                color: #e2e8f0;
            }
            /* 批量总结报告样式 */
            .ai-batch-report h2 { color: #3b82f6; border-bottom: 1px solid #ddd; padding-bottom: 5px; margin-top: 0; }
            .ai-batch-report h3 { color: #333; margin-top: 15px; margin-bottom: 5px; }
            .ai-batch-report ul { margin-left: 20px; margin-bottom: 10px; }
            body.ttrss_dark .ai-batch-report h2 { color: #60a5fa; }
            body.ttrss_dark .ai-batch-report h3 { color: #ccc; }
        ";
    }

    // --- 钩子 1: 设置界面 (Qwen Only) ---
    function hook_prefs_tab($args) {
        if ($args != "prefPrefs") return;

        print "<div dojoType=\"dijit.layout.ContentPane\" title=\"AI Summary Settings\">";
        print "<h3>AI Configuration (Qwen Only)</h3>";
        
        print "<form dojoType=\"dijit.form.Form\">";
        print "<script type=\"dojo/method\" event=\"onSubmit\" args=\"evt\">
            evt.preventDefault();
            if (this.validate()) {
                dojo.xhrPost({
                    url: \"backend.php\",
                    content: {
                        op: \"pluginhandler\",
                        plugin: \"ai_summary\",
                        method: \"save_prefs\",
                        csrf_token: __csrf_token,
                        params: dojo.toJson(dojo.formToObject(this.domNode))
                    },
                    load: function(response) {
                        if (typeof Notify != 'undefined') Notify.msg('Settings Saved', true);
                        else console.log('Settings Saved');
                    }
                });
            }
        </script>";

        $qwen_key = $this->host->get($this, "qwen_api_key");
        $auto_cats = $this->host->get($this, "auto_summarize_cats");

        print "<label>Qwen API Key:</label> ";
        print "<input dojoType=\"dijit.form.ValidationTextBox\" name=\"qwen_api_key\" value=\"$qwen_key\" style=\"width: 300px\" type=\"password\" placeholder=\"Required\"><br/>";
        
        print "<label>Auto-summarize Category IDs:</label> ";
        print "<input dojoType=\"dijit.form.ValidationTextBox\" name=\"auto_summarize_cats\" value=\"$auto_cats\" style=\"width: 300px\" placeholder=\"e.g. 1,4,7\"><br/>";

        print "<button dojoType=\"dijit.form.Button\" type=\"submit\">Save Settings</button>";
        print "</form></div>";
    }

    function save_prefs() {
        $params = $_REQUEST['params'] ?? $_REQUEST;
        if (is_string($params)) $params = json_decode($params, true);
        
        $this->host->set($this, "qwen_api_key", $params["qwen_api_key"] ?? "");
        $this->host->set($this, "auto_summarize_cats", $params["auto_summarize_cats"] ?? "");
        print json_encode(array("message" => "Settings saved."));
    }

    // --- 钩子 2: 文章内部按钮 ---
    function hook_article_button($line) {
        $icon_src = "data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHZpZXdCb3g9IjAgMCAyNCAyNCIgZmlsbD0iIzNiODJmNiI+PHBhdGggZD0iTTE5IDlsMS4yNS0yLjc1TDIzIDVsLTIuNzUtMS4yNUwxOSAxbC0xLjI1IDIuNzVMMTUgNWwyLjc1IDEuMjVMMTkgOXptLTcuNS41TDkgNCA2LjUgOS41IDEgMTJsNS41IDIuNUw5IDIwbDIuNS01LjVMMTcgMTJsLTUuNS0yLjV6TTE5IDE1bC0xLjI1IDIuNzVMMTUgMTlsMi43NSAxLjI1TDE5IDE1eiIvPjwvc3ZnPg==";
        return "<img src=\"$icon_src\" style=\"cursor:pointer;vertical-align:middle;margin-left:5px;width:18px;height:18px;\" 
                title=\"Generate AI Summary\" onclick=\"Plugins.AI_Summary.summarize(".$line["id"].")\" />";
    }

    // --- 钩子 3: 自动处理 ---
    function hook_article_filter($article) {
        if (!isset($article["feed_id"])) return $article;

        $auto_cats_str = $this->host->get($this, "auto_summarize_cats");
        if (empty($auto_cats_str)) return $article;
        $auto_cats = explode(",", $auto_cats_str);
        
        $pdo = Db::pdo();
        $sth = $pdo->prepare("SELECT cat_id FROM ttrss_feeds WHERE id = ?");
        $sth->execute([$article["feed_id"]]);
        
        if ($row = $sth->fetch()) {
            if (in_array($row['cat_id'], $auto_cats)) {
                $summary = $this->generate_summary($article["title"], $article["content"]);
                if ($this->is_valid_response($summary)) {
                    $article["content"] = $this->format_summary_html($summary) . $article["content"];
                }
            }
        }
        return $article;
    }

    // --- 核心逻辑: 批量总结 ---
    function batch_summarize() {
        $ids_str = $_REQUEST['ids'] ?? "";
        if (empty($ids_str)) {
            print json_encode(array("status" => "error", "message" => "No articles selected"));
            return;
        }

        $ids = explode(",", $ids_str);
        $pdo = Db::pdo();
        $uid = $_SESSION['uid'];
        
        $articles_text = "";
        $count = 0;
        
        foreach ($ids as $id) {
            $id = (int)$id;
            $sth = $pdo->prepare("SELECT title, content FROM ttrss_entries, ttrss_user_entries 
                WHERE ref_id = id AND id = ? AND owner_uid = ?");
            $sth->execute([$id, $uid]);
            
            if ($row = $sth->fetch()) {
                $count++;
                $title = $row['title'];
                $clean_content = strip_tags($row['content']);
                $clean_content = mb_substr($clean_content, 0, 1500);
                
                $articles_text .= "文章 $count: [$title]\n内容: $clean_content\n\n----------------\n\n";
            }
        }

        if (empty($articles_text)) {
            print json_encode(array("status" => "error", "message" => "Could not retrieve content for selected articles"));
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
            
            print json_encode(array("status" => "success", "html" => $html));
        } else {
            print json_encode(array("status" => "error", "message" => $result));
        }
    }

    // --- 核心逻辑: 单篇总结 ---
    function manual_summarize() {
        $id = (int) $_REQUEST['id'];
        $pdo = Db::pdo();
        $sth = $pdo->prepare("SELECT title, content FROM ttrss_entries, ttrss_user_entries 
            WHERE ref_id = id AND id = ? AND owner_uid = ?");
        $sth->execute([$id, $_SESSION['uid']]);
        
        if ($row = $sth->fetch()) {
            $summary = $this->generate_summary($row['title'], $row['content']);
            if ($this->is_valid_response($summary)) {
                print json_encode(array("status" => "success", "html" => $this->format_summary_html($summary)));
            } else {
                print json_encode(array("status" => "error", "message" => $summary));
            }
        } else {
            print json_encode(array("status" => "error", "message" => "Article not found"));
        }
    }

    // --- 新增：手动标记已读 (后端实现) ---
    function mark_read() {
        $ids_str = $_REQUEST['ids'] ?? "";
        if (empty($ids_str)) {
            print json_encode(array("status" => "error", "message" => "No IDs provided"));
            return;
        }
        
        $ids = explode(",", $ids_str);
        $ids = array_map('intval', $ids);
        $ids = array_filter($ids); // 移除0值
        
        if (empty($ids)) {
             print json_encode(array("status" => "error", "message" => "Invalid IDs"));
             return;
        }

        $pdo = Db::pdo();
        $uid = $_SESSION['uid'];
        
        // 构建占位符
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        
        // 执行更新
        $sql = "UPDATE ttrss_user_entries 
                SET unread = false, last_read = NOW() 
                WHERE ref_id IN ($placeholders) AND owner_uid = ?";
        
        // 合并 ID 参数和 UID 参数
        $params = array_merge($ids, [$uid]);
        
        $sth = $pdo->prepare($sql);
        $sth->execute($params);
        
        $count = $sth->rowCount();
        
        print json_encode(array("status" => "success", "updated" => $count));
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

    function api_version() { return 2; }
}
?>