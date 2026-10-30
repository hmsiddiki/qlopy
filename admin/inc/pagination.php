<?php
if (!defined('QLOPY_INIT')) { http_response_code(403); exit; }

if (!function_exists('qp_admin_pagination')) {
    /**
     * Page links for an admin list: "Page # [ ] Go" on the left and "‹ 1 … 4 5 [6] 7 8 … 34 ›" on the right, instead of one link per page, so a long list
     * never runs out of the page and any page can be reached. The first and last page, the current page with $around pages each side, and previous / next
     * are always shown; the gaps become "…". The "Page #" box takes a number and goes to that page (Enter or Go).
     *
     * @param int      $current Current page (1-based).
     * @param int      $total   Number of pages; nothing is printed for fewer than two.
     * @param callable $url     fn(int $page): string, the address of one page (it is escaped here).
     * @param string   $label   Accessible name of the navigation.
     * @param int      $around  Pages shown on each side of the current one.
     */
    function qp_admin_pagination(int $current, int $total, callable $url, string $label = 'Pagination', int $around = 2): void
    {
        static $scriptPrinted = false;
        if ($total < 2) {
            return;
        }
        $current = max(1, min($total, $current));
        $pages = [1, $total];
        for ($i = $current - $around; $i <= $current + $around; $i++) {
            if ($i >= 1 && $i <= $total) {
                $pages[] = $i;
            }
        }
        $pages = array_values(array_unique($pages));
        sort($pages);

        $h = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
        $link = static function (int $p, string $text, string $extra = '') use ($url, $h): string {
            return '<a class="page-link" href="' . $h((string) $url($p)) . '"' . $extra . '>' . $text . '</a>';
        };
        // The address of any page, for the "Page #" box: the page number is swapped into this one.
        $mark = '987654321';
        $template = (string) $url((int) $mark);

        echo '<nav aria-label="' . $h($label) . '" class="mt-2 d-flex flex-wrap align-items-center justify-content-between qp-pg">';
        // "Page # [ ] Go": a plain input and button (not a form: these lists sit inside the bulk-actions form).
        echo '<div class="d-flex align-items-center mb-2 qp-pg-go" data-url="' . $h($template) . '" data-mark="' . $mark . '" data-max="' . $total . '">'
            . '<input type="number" class="form-control form-control-sm qp-pg-num" min="1" max="' . $total . '" placeholder="Page #" aria-label="Go to page (1 to ' . $total . ')" style="width:88px">'
            . '<button type="button" class="btn btn-sm btn-outline-secondary ml-2 qp-pg-btn">Go</button>'
            . '</div>';
        echo '<ul class="pagination flex-wrap mb-2">';
        echo $current > 1
            ? '<li class="page-item">' . $link($current - 1, '&lsaquo;', ' rel="prev" aria-label="Previous page"') . '</li>'
            : '<li class="page-item disabled"><span class="page-link" aria-hidden="true">&lsaquo;</span></li>';
        $last = 0;
        foreach ($pages as $p) {
            if ($last && $p - $last > 1) {
                echo '<li class="page-item disabled"><span class="page-link">&hellip;</span></li>';
            }
            echo $p === $current
                ? '<li class="page-item active" aria-current="page"><span class="page-link">' . $p . '</span></li>'
                : '<li class="page-item">' . $link($p, (string) $p) . '</li>';
            $last = $p;
        }
        echo $current < $total
            ? '<li class="page-item">' . $link($current + 1, '&rsaquo;', ' rel="next" aria-label="Next page"') . '</li>'
            : '<li class="page-item disabled"><span class="page-link" aria-hidden="true">&rsaquo;</span></li>';
        echo '</ul></nav>';

        if (!$scriptPrinted) {
            $scriptPrinted = true;
            echo '<script>(function(){function go(box){var n=box.querySelector(".qp-pg-num"),v=parseInt(n.value,10),max=parseInt(box.getAttribute("data-max"),10);'
                . 'if(!v||v<1){n.focus();return;}if(v>max)v=max;location.href=box.getAttribute("data-url").replace(box.getAttribute("data-mark"),String(v));}'
                . 'document.addEventListener("click",function(e){var b=e.target.closest&&e.target.closest(".qp-pg-btn");if(b)go(b.closest(".qp-pg-go"));});'
                . 'document.addEventListener("keydown",function(e){if(e.key==="Enter"&&e.target.classList&&e.target.classList.contains("qp-pg-num")){e.preventDefault();go(e.target.closest(".qp-pg-go"));}});})();</script>';
        }
    }
}
