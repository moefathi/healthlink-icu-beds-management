document.querySelectorAll('form[data-confirm]').forEach(function (form) {
    form.addEventListener('submit', function (event) {
        if (!window.confirm(form.getAttribute('data-confirm'))) {
            event.preventDefault();
        }
    });
});

var logs = document.getElementById('monitoringLogs');
var addLog = document.getElementById('addLog');
if (logs && addLog) {
    var emptyRow = logs.lastElementChild.cloneNode(true);
    var nextIndex = logs.children.length;
    addLog.addEventListener('click', function () {
        if (logs.children.length >= 200) {
            return;
        }
        var row = emptyRow.cloneNode(true);
        row.querySelectorAll('input').forEach(function (input) {
            input.name = input.name.replace(/\[\d+\]/, '[' + nextIndex + ']');
            input.value = '';
        });
        nextIndex++;
        logs.appendChild(row);
    });
    logs.addEventListener('click', function (event) {
        if (event.target.classList.contains('remove-log')) {
            event.target.parentElement.remove();
        }
    });
}
