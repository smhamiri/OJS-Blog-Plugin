{include file="frontend/components/header.tpl" pageTitleTranslated="Post"}

<main class="page-blog container" style="padding-top:30px;padding-bottom:40px;">
    <h1 style="margin:0 0 8px;">Post</h1>
    <p style="margin:0 0 25px;color:#666;">{$total|escape} {if $total == 1}blog entry{else}blog entries{/if}</p>

    {if $total == 0}
        <div class="pkp_notification pkp_notification_info">No blog entries are available.</div>
    {else}
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:22px;">
            {foreach from=$posts item=post}
                <article style="background:#fff;border:1px solid #e1e6e4;border-radius:12px;padding:22px;box-shadow:0 5px 18px rgba(31,61,52,.08);height:100%;">
                    <div style="font-size:14px;color:#66736f;margin-bottom:8px;">
                        {$post.date|escape}{if $post.byline} · By {$post.byline|escape}{/if}
                    </div>
                    <h2 style="margin:0 0 12px;font-size:24px;line-height:1.25;">
                        <a href="{$post.url|escape}" style="color:#184d43;text-decoration:none;">{$post.title|escape}</a>
                    </h2>
                    <div style="color:#45534f;line-height:1.6;margin-bottom:16px;">
                        {$post.excerpt|escape}
                    </div>
                    <a href="{$post.url|escape}" style="display:inline-block;background:#536aa0;color:#fff;padding:9px 15px;border-radius:7px;text-decoration:none;font-weight:600;">Read More →</a>
                </article>
            {/foreach}
        </div>
    {/if}
</main>

{include file="frontend/components/footer.tpl"}
