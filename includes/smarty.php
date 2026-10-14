<?php
// Smarty — AI assistant for shop users (read-only Phase 1).
declare(strict_types=1);

const SMARTY_RATE_LIMIT = 20;        // messages per user per hour
const SMARTY_HISTORY    = 6;         // last N exchanges sent back for context

function smarty_config(): array {
    static $cfg = null;
    if ($cfg !== null) return $cfg;
    try {
        $r = db()->query('SELECT * FROM smarty_config WHERE id = 1')->fetch();
    } catch (Throwable $e) { $r = null; }
    return $cfg = is_array($r) ? $r : ['provider'=>'gemini','model'=>'gemini-flash-latest','api_key'=>'','enabled'=>0,'max_tokens'=>600];
}

function smarty_enabled(): bool {
    $c = smarty_config();
    return (int)$c['enabled'] === 1 && $c['api_key'] !== '';
}

function smarty_config_save(string $provider, string $model, string $apiKey, bool $enabled, int $maxTokens): void {
    $cur = smarty_config();
    $key = $apiKey !== '' ? $apiKey : ($cur['api_key'] ?? '');
    db()->prepare('REPLACE INTO smarty_config (id, provider, model, api_key, enabled, max_tokens)
                   VALUES (1, :p, :m, :k, :e, :t)')
        ->execute([':p'=>$provider, ':m'=>$model, ':k'=>$key, ':e'=>$enabled?1:0, ':t'=>$maxTokens]);
}

/** Build the live-data packet for one shop. Compact — stays under ~2 KB. */
function smarty_context(int $shopId): array {
    $pdo = db();

    $q = $pdo->prepare('SELECT name, phone, email FROM shops WHERE id = :id');
    $q->execute([':id'=>$shopId]);
    $shop = $q->fetch() ?: ['name'=>'this shop'];

    $today = date('Y-m-d');

    $todaySales = $pdo->prepare("SELECT COUNT(*) n, COALESCE(SUM(grand_total),0) t
                                 FROM sales WHERE shop_id=:s AND status<>'cancelled' AND DATE(sale_date)=:d");
    $todaySales->execute([':s'=>$shopId, ':d'=>$today]); $tS = $todaySales->fetch();

    $cq = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM payments WHERE shop_id=:s AND status='verified' AND DATE(paid_at)=:d");
    $cq->execute([':s'=>$shopId, ':d'=>$today]);
    $collectedToday = (float)$cq->fetchColumn();

    $monthSales = $pdo->prepare("SELECT COALESCE(SUM(grand_total),0) FROM sales
                                 WHERE shop_id=:s AND status<>'cancelled'
                                   AND YEAR(sale_date)=YEAR(CURDATE()) AND MONTH(sale_date)=MONTH(CURDATE())");
    $monthSales->execute([':s'=>$shopId]); $mT = (float)$monthSales->fetchColumn();

    $outstandingQ = $pdo->prepare("
      SELECT COALESCE(SUM(s.grand_total),0) -
             COALESCE((SELECT SUM(pa.allocated_amount) FROM payment_allocations pa
                       JOIN payments p ON p.id=pa.payment_id
                       JOIN sales s2 ON s2.id=pa.sale_id
                       WHERE s2.shop_id=:s2 AND p.status='verified'),0)
      FROM sales s WHERE s.shop_id=:s AND s.status<>'cancelled'");
    $outstandingQ->execute([':s'=>$shopId, ':s2'=>$shopId]);
    $outstanding = max(0, (float)$outstandingQ->fetchColumn());

    $lowStockQ = $pdo->prepare("SELECT COUNT(*) FROM products
       WHERE shop_id=:s AND is_available=1
         AND ((min_stock_alert>0 AND current_stock<=min_stock_alert) OR current_stock<=0)");
    $lowStockQ->execute([':s'=>$shopId]);
    $lowStock = (int)$lowStockQ->fetchColumn();

    $lowProducts = $pdo->prepare("SELECT name, current_stock, unit FROM products
       WHERE shop_id=:s AND is_available=1
         AND ((min_stock_alert>0 AND current_stock<=min_stock_alert) OR current_stock<=0)
       ORDER BY current_stock ASC LIMIT 5");
    $lowProducts->execute([':s'=>$shopId]);
    $lowList = $lowProducts->fetchAll();

    $topProd = $pdo->prepare("SELECT si.product_name AS name, SUM(si.qty) AS units, SUM(si.line_total) AS revenue
       FROM sale_items si JOIN sales s ON s.id=si.sale_id
       WHERE s.shop_id=:s AND s.status<>'cancelled'
         AND DATE(s.sale_date) >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
       GROUP BY si.product_name ORDER BY revenue DESC LIMIT 5");
    $topProd->execute([':s'=>$shopId]);
    $topList = $topProd->fetchAll();

    $topDebt = $pdo->prepare("
      SELECT c.name, c.mobile,
        (COALESCE((SELECT SUM(grand_total) FROM sales WHERE customer_id=c.id AND status<>'cancelled'),0)
       - COALESCE((SELECT SUM(pa.allocated_amount) FROM payment_allocations pa
                   JOIN payments p ON p.id=pa.payment_id
                   JOIN sales s ON s.id=pa.sale_id
                   WHERE s.customer_id=c.id AND p.status='verified'),0)) AS due
      FROM customers c WHERE c.shop_id=:s
      HAVING due > 0.01 ORDER BY due DESC LIMIT 5");
    $topDebt->execute([':s'=>$shopId]);
    $debtList = $topDebt->fetchAll();

    $overdueQ = $pdo->prepare("SELECT COUNT(*) FROM sales WHERE shop_id=:s AND status IN ('unpaid','partial')
       AND due_date IS NOT NULL AND due_date <= CURDATE()");
    $overdueQ->execute([':s'=>$shopId]);
    $overdue = (int)$overdueQ->fetchColumn();

    $pendingUpiQ = $pdo->prepare("SELECT COUNT(*) FROM payments WHERE shop_id=:s AND status='pending'");
    $pendingUpiQ->execute([':s'=>$shopId]);
    $pendingUpi = (int)$pendingUpiQ->fetchColumn();

    $cc = $pdo->prepare("SELECT COUNT(*) FROM customers WHERE shop_id=:s"); $cc->execute([':s'=>$shopId]);
    $customerCount = (int)$cc->fetchColumn();
    $pc = $pdo->prepare("SELECT COUNT(*) FROM products WHERE shop_id=:s"); $pc->execute([':s'=>$shopId]);
    $productCount = (int)$pc->fetchColumn();

    return [
        'shop_name'      => $shop['name'],
        'today'          => $today,
        'today_orders'   => (int)$tS['n'],
        'today_sales'    => (float)$tS['t'],
        'today_collected'=> $collectedToday,
        'month_sales'    => $mT,
        'outstanding'    => $outstanding,
        'low_stock_count'=> $lowStock,
        'low_stock_items'=> $lowList,
        'top_products_30d' => $topList,
        'top_debtors'    => $debtList,
        'overdue_reminders' => $overdue,
        'pending_upi'    => $pendingUpi,
        'customer_count' => $customerCount,
        'product_count'  => $productCount,
    ];
}

function smarty_rate_exceeded(int $shopId, int $userId): bool {
    $q = db()->prepare("SELECT COUNT(*) FROM smarty_log
                        WHERE shop_id=:s AND user_id=:u AND created_at >= (NOW() - INTERVAL 1 HOUR)");
    $q->execute([':s'=>$shopId, ':u'=>$userId]);
    return (int)$q->fetchColumn() >= SMARTY_RATE_LIMIT;
}

/** Recent history for this shop/user — [{q, a}, ...] oldest-first. */
function smarty_history(int $shopId, int $userId, int $n = SMARTY_HISTORY): array {
    $q = db()->prepare('SELECT question, answer FROM smarty_log
                        WHERE shop_id=:s AND user_id=:u AND ok=1
                        ORDER BY id DESC LIMIT :n');
    $q->bindValue(':s', $shopId, PDO::PARAM_INT);
    $q->bindValue(':u', $userId, PDO::PARAM_INT);
    $q->bindValue(':n', $n, PDO::PARAM_INT);
    $q->execute();
    return array_reverse($q->fetchAll());
}

/** Core: build messages → call Gemini → return [ok, text, error]. */
function smarty_ask(int $shopId, int $userId, string $question): array {
    $question = trim($question);
    if ($question === '') return ['ok'=>false, 'text'=>'', 'error'=>'Ask me something!'];

    $cfg = smarty_config();
    if (!smarty_enabled()) return ['ok'=>false, 'text'=>'', 'error'=>'Smarty is not enabled yet. Ask your admin to add the API key.'];
    if (smarty_rate_exceeded($shopId, $userId)) return ['ok'=>false, 'text'=>'', 'error'=>'You hit the hourly limit (' . SMARTY_RATE_LIMIT . ' messages). Try again later.'];

    $ctx     = smarty_context($shopId);
    $history = smarty_history($shopId, $userId);

    $systemPrompt = _smarty_system_prompt($ctx);

    // Gemini payload: role=user and role=model turns.
    $contents = [['role' => 'user', 'parts' => [['text' => $systemPrompt]]]];
    $contents[] = ['role' => 'model', 'parts' => [['text' => "Understood — I'll help with " . $ctx['shop_name'] . " using the data above. What would you like to know?"]]];
    foreach ($history as $h) {
        $contents[] = ['role' => 'user',  'parts' => [['text' => $h['question']]]];
        $contents[] = ['role' => 'model', 'parts' => [['text' => $h['answer']]]];
    }
    $contents[] = ['role' => 'user', 'parts' => [['text' => $question]]];

    // Try configured model first; on 503/overload, fall back through alternates.
    $body = json_encode([
        'contents' => $contents,
        'generationConfig' => [
            'temperature'     => 0.3,
            'maxOutputTokens' => (int)$cfg['max_tokens'],
        ],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    $modelsToTry = array_values(array_unique(array_filter([
        $cfg['model'],
        'gemini-flash-latest',       // auto-updating alias
        'gemini-flash-lite-latest',  // auto-updating lite alias
        'gemini-pro-latest',         // auto-updating pro alias
    ])));

    $resp = false; $code = 0; $err = ''; $usedModel = $cfg['model'];
    foreach ($modelsToTry as $m) {
        $usedModel = $m;
        $url = 'https://generativelanguage.googleapis.com/v1beta/models/' . urlencode($m) . ':generateContent?key=' . urlencode($cfg['api_key']);
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_TIMEOUT        => 25,
        ]);
        $resp = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        // Success → stop. 429/503 (overload) or 404 (model gone) → try next model.
        if ($resp !== false && $code >= 200 && $code < 300) break;
        if (!in_array($code, [404, 429, 503], true)) break;
        usleep(300000); // 300 ms pause before next try
    }

    if ($resp === false) return _smarty_log_and_return($shopId, $userId, $question, false, '', 'Network error: ' . $err);
    if ($code === 503)   return _smarty_log_and_return($shopId, $userId, $question, false, '', 'Smarty is busy right now (Google overloaded). Please try again in a moment.');
    if ($code === 429)   return _smarty_log_and_return($shopId, $userId, $question, false, '', 'Rate limit hit on Google. Try again in a minute.');
    if ($code < 200 || $code >= 300) return _smarty_log_and_return($shopId, $userId, $question, false, '', 'API error (' . $code . '): ' . substr($resp, 0, 200));

    $json = json_decode($resp, true);
    $text = $json['candidates'][0]['content']['parts'][0]['text'] ?? '';
    if ($text === '') {
        $block = $json['promptFeedback']['blockReason'] ?? ($json['candidates'][0]['finishReason'] ?? 'empty');
        return _smarty_log_and_return($shopId, $userId, $question, false, '', 'No answer (' . $block . ')');
    }

    $tokensIn  = (int)($json['usageMetadata']['promptTokenCount']    ?? 0);
    $tokensOut = (int)($json['usageMetadata']['candidatesTokenCount']?? 0);
    _smarty_log_and_return($shopId, $userId, $question, true, $text, null, $tokensIn, $tokensOut);
    return ['ok'=>true, 'text'=>$text, 'error'=>null];
}

function _smarty_log_and_return(int $shopId, int $userId, string $question, bool $ok, string $answer, ?string $error, int $tin=0, int $tout=0): array {
    try {
        db()->prepare('INSERT INTO smarty_log (shop_id, user_id, question, answer, ok, error, tokens_in, tokens_out)
                       VALUES (:s, :u, :q, :a, :ok, :e, :ti, :to)')
            ->execute([':s'=>$shopId, ':u'=>$userId, ':q'=>$question, ':a'=>$answer, ':ok'=>$ok?1:0,
                       ':e'=>$error, ':ti'=>$tin, ':to'=>$tout]);
    } catch (Throwable $e) { error_log('[smarty_log] ' . $e->getMessage()); }
    return ['ok'=>$ok, 'text'=>$answer, 'error'=>$error];
}

function _smarty_system_prompt(array $ctx): string {
    $money = fn($n) => '₹' . number_format((float)$n, 2);

    $low = '';
    foreach ($ctx['low_stock_items'] as $p) {
        $low .= '- ' . $p['name'] . ' (' . rtrim(rtrim(number_format((float)$p['current_stock'],3,'.',''),'0'),'.') . ' ' . $p['unit'] . ")\n";
    }
    $top = '';
    foreach ($ctx['top_products_30d'] as $p) {
        $top .= '- ' . $p['name'] . ' — ' . $money($p['revenue']) . "\n";
    }
    $debt = '';
    foreach ($ctx['top_debtors'] as $d) {
        $debt .= '- ' . $d['name'] . ($d['mobile'] ? ' (' . $d['mobile'] . ')' : '') . ' — ' . $money($d['due']) . "\n";
    }

    return <<<SYS
You are Smarty, a friendly helpful assistant for {$ctx['shop_name']} — a small retail shop using SmartBils billing software.

You help the shopkeeper with:
- Quick answers about their shop (sales, dues, stock, products, customers).
- How-to guidance for the SmartBils panel (POS, products, settings, reports, returns, suppliers, staff).
- Short, polite replies. Use plain English, Hindi or Odia depending on what the user writes.
- Keep responses under 120 words unless asked for detail.
- Format numbers with ₹ prefix and Indian commas.
- NEVER invent numbers. If a figure isn't in the data below, say "I don't have that data" and suggest where in the panel to find it.

LIVE DATA for {$ctx['shop_name']} (as of {$ctx['today']}):
- Today orders: {$ctx['today_orders']}
- Today gross sales: {$money($ctx['today_sales'])}
- Today collected (verified): {$money($ctx['today_collected'])}
- This month gross sales: {$money($ctx['month_sales'])}
- Outstanding dues (all customers): {$money($ctx['outstanding'])}
- Low / out-of-stock products: {$ctx['low_stock_count']}
- Pending UPI payments: {$ctx['pending_upi']}
- Overdue reminders (promised date today or earlier): {$ctx['overdue_reminders']}
- Total customers: {$ctx['customer_count']}
- Total products: {$ctx['product_count']}

Low-stock sample (up to 5):
{$low}
Top products — last 30 days (up to 5):
{$top}
Top debtors (up to 5):
{$debt}

PANEL HOW-TO HINTS (where to click):
- New bill: sidebar "New Bill" (customer mobile → name → add products → confirm)
- Collect old dues: sidebar "Collect Due" (mobile → pick invoices → amount → save)
- Reminders: sidebar "Reminders" (WhatsApp/SMS one-tap to overdue customers)
- Add product: sidebar "Products" → "Add product"
- Returns: sidebar "Returns" → "New return" (invoice no / customer mobile / name)
- Shop logo, UPI QR, receipt header/footer, tax: sidebar "Settings"
- Add staff: sidebar "Staff" → "Add staff" (set permissions)
- Change password: avatar menu → "Change password"
- Reports: sidebar "Reports" (sales, products, dues, profit, stock)

Rules:
- Never claim you took an action — you can only READ and ADVISE.
- If asked to CREATE/EDIT/DELETE, say the user needs to do it from the panel and point to the right page.
- Keep it warm and shop-friendly.
SYS;
}
