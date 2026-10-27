<dialog id="collection-more" class="audience-wide-dialog collection-actions-dialog" aria-labelledby="collection-more-title">
    <div class="dialog-heading"><h2 id="collection-more-title">More actions</h2><button type="button" data-close-dialog aria-label="Close">×</button></div>
    <div class="collection-more-body">
        <section class="collection-more-action" aria-labelledby="collection-pause-title">
            <div><h3 id="collection-pause-title">Pause public collection</h3><p>Temporarily stop new submissions without removing the form.</p></div>
            <form method="post"><?= $csrf ?><?php audienceInputs(['action'=>'collection_operation','id'=>$id,'version'=>$resource['version'],'operation'=>'pause']); ?><button type="submit">Pause collection</button></form>
        </section>
        <section class="collection-more-action" aria-labelledby="collection-duplicate-title">
            <div><h3 id="collection-duplicate-title">Duplicate form</h3><p>Create a new draft with this form’s content and settings.</p></div>
            <form method="post"><?= $csrf ?><?php audienceInputs(['action'=>'collection_operation','id'=>$id,'version'=>$resource['version'],'operation'=>'replicate']); ?><button type="submit">Duplicate form</button></form>
        </section>
        <section class="collection-more-action" aria-labelledby="collection-archive-heading">
            <div><h3 id="collection-archive-heading">Archive form</h3><p>Stop public collection and move this form to Archived. Existing results are kept.</p></div>
            <?php $collectionEntry=$resource; require __DIR__.'/collection-lifecycle-actions.php'; ?>
        </section>
    </div>
</dialog>
