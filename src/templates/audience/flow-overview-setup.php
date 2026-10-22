<?php $taskDone=count(array_filter($flowSetupTasks,static fn($task)=>$task['complete'])); ?>
<section class="flow-onboarding" data-flow-onboarding aria-labelledby="flow-onboarding-title">
    <h2 id="flow-onboarding-title">Get started with automation flows</h2>
    <div class="flow-onboarding-progress"><span data-flow-setup-status role="status" aria-live="polite"><?= $taskDone ?>/<?= count($flowSetupTasks) ?> tasks addressed</span><progress data-flow-setup-progress max="<?= count($flowSetupTasks) ?>" value="<?= $taskDone ?>" aria-label="Automation setup progress"></progress></div>
    <p class="flow-setup-detail" data-flow-setup-detail><?= $taskDone ?> completed · 0 dismissed</p>
    <div class="flow-setup-tasks">
    <?php $first=true;foreach($flowSetupTasks as$key=>$task): ?>
    <details class="flow-setup-task" data-flow-task="<?= $key ?>" data-flow-task-complete="<?= (int)$task['complete'] ?>"<?= $first?' open':'' ?>>
        <summary><span class="flow-task-check" aria-hidden="true"><?= audienceFlowIcon('status') ?></span><strong><?= $h($task['title']) ?></strong><span class="flow-task-kind"><?= $h($task['kind']) ?></span><span class="flow-task-state" data-flow-task-state><?= $task['complete']?'Completed':'To do' ?></span></summary>
        <div class="flow-task-body"><div><p><?= $h($task['description']) ?></p><div class="audience-actions"><a class="audience-button primary"<?= !$task['target']?' data-flow-open-guide':'' ?> href="<?= $h($task['target']?$url($task['target']):'#flow-guide-delivery') ?>"><?= $h($task['action']) ?></a><button type="button" data-flow-task-dismiss<?= $task['complete']?' hidden':'' ?>>Dismiss task</button><button type="button" data-flow-task-restore hidden>Restore task</button></div></div><div class="flow-task-art" aria-hidden="true"><?php audienceFlowTaskArtwork($key); ?></div></div>
    </details>
    <?php $first=false;endforeach; ?>
    </div>
    <div class="flow-setup-footer"><button type="button" data-flow-tasks-toggle aria-expanded="false">⌄ Show all</button><button type="button" data-flow-tasks-restore hidden>Restore dismissed tasks</button></div>
    <p class="flow-setup-note">Checklist completion does not turn on a flow. Dismissals and the library visit are remembered in this browser.</p>
</section>
