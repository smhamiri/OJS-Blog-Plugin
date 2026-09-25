{**
 * Blog plugin -- displays the same manager used by the plugin modal.
 *
 * The legacy grid endpoint is not used here. In OJS 3.5 it is not the
 * endpoint that powers the current Blog Manager and would leave this tab
 * waiting for a response indefinitely.
 *}
<tab id="blog" label="{translate key="plugins.generic.blog.blog"}">
    {$blogManagerHtml nofilter}
</tab>
