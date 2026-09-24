{**
 * Display a blog entry.
 *}
{include file="frontend/components/header.tpl" pageTitleTranslated=$entry->getTitle()}

<article class="container page-blog-post">
	<div class="row page-header justify-content-md-center">
	<div class="col-md-8" style="margin-bottom:15px;">
		<a href="{url router=PKP\core\PKPApplication::ROUTE_PAGE page="blog"}">← Back to Blog</a>
	</div>
</div>
<div class="row page-header justify-content-md-center">
		<div class="col-md-8">
			<div class="blog-date">{$formattedDate|escape}</div>
			<h1>{$entry->getTitle()|escape}</h1>
			<div class="byline">{if $entry->getByline()}By {$entry->getByline()|escape}{/if}</div>
		</div>
	</div>
	<div class="row justify-content-md-center blog-post-content">
		<div class="col-md-8">
			<article class="page-content blog-post-content">{$entry->getContent()}</article>
		</div>
	</div>
	<div class="row justify-content-md-center blog-post-tags">
		<div class="col-md-8">
			<article class="page-content">
				{foreach from=$keywords item=word}
					{foreach from=$word item=localizedWord}
						<a class="btn" href="{url router=$smarty.const.ROUTE_PAGE page="blog" keyword=$localizedWord}">{$localizedWord|escape}</a>
					{/foreach}
				{/foreach}
			</article>
		</div>
	</div>
</article>

{include file="frontend/components/footer.tpl"}
