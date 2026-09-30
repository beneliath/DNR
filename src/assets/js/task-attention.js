(function () {
    'use strict';
    // Also clear old animation classes if a cached page resumes after an update.
    document.querySelectorAll('.task-attention-cascading, .task-row-attention-current').forEach(function (element) {
        element.classList.remove('task-attention-cascading', 'task-row-attention-current');
    });
}());
