{**
 * Form for editing a blog entry.
 *}
<script>
	$(function() {ldelim}
		$('#blogEntryForm').pkpHandler('$.pkp.controllers.form.AjaxFormHandler');
	{rdelim});
</script>

{capture assign=actionUrl}{url router=$smarty.const.ROUTE_COMPONENT component="plugins.generic.blog.controllers.grid.BlogGridHandler" op="updateBlogEntry" escape=false}{/capture}
<form class="pkp_form" id="blogEntryForm" method="post" action="{$actionUrl}">
	{csrf}
	{if $blogEntryId}
		<input type="hidden" name="blogEntryId" value="{$blogEntryId|escape}" />
	{/if}

	{fbvFormArea id="blogEntryFormArea" class="border"}
		{fbvFormSection label="plugins.generic.blog.pageTitle" for="title"}
			{fbvElement type="text" id="title" value=$title maxlength="255"}
		{/fbvFormSection}

		{fbvFormSection label="plugins.generic.blog.content" for="content"}
			{fbvElement type="textarea" id="content" value=$content rich=true plugins="link image lists table code fullscreen" toolbar="undo redo | blocks | bold italic underline | alignleft aligncenter alignright alignjustify | bullist numlist | link image table | code fullscreen"}
		{/fbvFormSection}

		{fbvFormSection label="plugins.generic.blog.byline" for="byline"}
			{fbvElement type="text" id="byline" value=$byline maxlength="255"}
		{/fbvFormSection}

		{fbvFormSection label="plugins.generic.blog.datePosted" for="datePosted"}
			{fbvElement type="text" id="datePosted" value=$datePosted class="datepicker"}
		{/fbvFormSection}
	{/fbvFormArea}

	{fbvFormArea id="tagitFields" class="border"}
		{fbvFormSection label="common.keywords"}
			{fbvElement type="keyword" id="keywords" current=$keywords}
		{/fbvFormSection}
	{/fbvFormArea}

	{fbvFormSection class="formButtons"}
		{assign var=buttonId value="submitFormButton"|concat:"-"|uniqid}
		{fbvElement type="submit" class="submitFormButton" id=$buttonId label="common.save"}
	{/fbvFormSection}
</form>
