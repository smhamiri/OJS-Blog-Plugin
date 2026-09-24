<?php
namespace APP\plugins\generic\blog\pages;

use PKP\handler\PKPHandler;
use PKP\db\DAORegistry;
use APP\template\TemplateManager;
use PKP\core\PKPApplication;

class BlogHandler extends PKPHandler
{
    protected static $plugin;
    public static function setPlugin($plugin): void { self::$plugin = $plugin; }

    public function index($args, $request)
    {
        $context = $request->getContext();
        if (!$context) {
            $request->getRouter()->handle404();
            return;
        }

        $dao = DAORegistry::getDAO('BlogEntryDAO');

        // IMPORTANT: use the same proven context query that worked in v3.2.1.6.
        // We intentionally do NOT put is_enabled in the SQL WHERE clause here.
        // The manager already proves that the context query returns the posts;
        // filtering the status in PHP avoids a public-page regression caused by
        // status-column/query differences on existing installations.
        $result = $dao->getEntriesByContextId((int) $context->getId(), null, null, null, true);

        // Convert DAO objects to simple arrays before passing them to Smarty.
        // This avoids OJS 3.5/Smarty expression parsing issues with method calls
        // such as $entry->getId() inside template expressions.
        $posts = [];
        $router = $request->getDispatcher();

        while ($entry = $result->next()) {
            // Disabled entries remain in the manager/database, but are hidden
            // from the public Blog. Keep this filter in PHP for compatibility.
            if (method_exists($entry, 'isEnabled') && !$entry->isEnabled()) {
                continue;
            }

            $id = (int) $entry->getId();
            $rawContent = (string) $entry->getContent();
            $plainContent = trim(preg_replace('/\s+/', ' ', strip_tags($rawContent)));
            $excerpt = mb_substr($plainContent, 0, 260);
            if (mb_strlen($plainContent) > 260) {
                $excerpt .= '…';
            }

            $rawDate = (string) $entry->getDatePosted();
            try {
                $formattedDate = (new \DateTime($rawDate))->format('F j, Y');
            } catch (\Throwable $e) {
                $formattedDate = $rawDate;
            }

            $posts[] = [
                'id' => $id,
                'title' => (string) $entry->getTitle(),
                'byline' => (string) $entry->getByline(),
                'date' => $formattedDate,
                'excerpt' => $excerpt,
                'url' => $router->url(
                    $request,
                    PKPApplication::ROUTE_PAGE,
                    $context->getPath(),
                    'blog',
                    'view',
                    [$id]
                ),
            ];
        }

        $blogUrl = $router->url(
            $request,
            PKPApplication::ROUTE_PAGE,
            $context->getPath(),
            'blog'
        );

        $templateMgr = TemplateManager::getManager($request);
        $templateMgr->assign([
            'posts' => $posts,
            'blogUrl' => $blogUrl,
            'total' => count($posts),
            'blogContextId' => (int) $context->getId(),
        ]);
        $templateMgr->display(self::$plugin->getTemplateResource('index.tpl'));
    }

    public function view($args,$request)
    {
        $context=$request->getContext(); $id=isset($args[0])?(int)$args[0]:0;
        if(!$context||!$id){$request->getRouter()->handle404();return;}
        $dao = DAORegistry::getDAO('BlogEntryDAO');
        $dao->ensureStatusColumn();
        $entry=$dao->getById($id);
        if(!$entry || (int)$entry->getContextId()!==(int)$context->getId() || (method_exists($entry, 'isEnabled') && !$entry->isEnabled())){$request->getRouter()->handle404();return;}
        $formattedDate = (string) $entry->getDatePosted();
        try {
            $formattedDate = (new \DateTime($formattedDate))->format('F j, Y');
        } catch (\Throwable $e) {
            // Keep the raw value as a last-resort fallback.
        }
        $tm=TemplateManager::getManager($request);
        $tm->assign(['entry'=>$entry,'keywords'=>$entry->getKeywords(),'formattedDate'=>$formattedDate]);
        $tm->display(self::$plugin->getTemplateResource('entry.tpl'));
    }
}
