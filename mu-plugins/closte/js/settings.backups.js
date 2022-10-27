jQuery(document).ready(function ($) {

    var closteCheckStatusInterval = null;
    var pendingBackups = $(".closte_pending_task");

    if (pendingBackups.length > 0) {
        closteCheckStatusInterval = setInterval(checkTasks, 5000);
    }


    function checkTasks() {

        var array = [];
        $(".closte_pending_task").each(function () {
            array.push($(this).data("task-id"));
        });


        if (array.length == 0) {
            clearInterval(closteCheckStatusInterval);
            return;
        }

        var closte_task_id = array[array.length - 1];

        var data = {
            'action': 'closte_get_task_status',
            'closte_task_id': closte_task_id
        };


        $.ajax({
            type: 'POST',
            url: ajaxurl,
            async: false,
            data: data,
            success: function (response) {
                if (response.success) {

                    if (response.task.end_date != null) {

                        if (response.task.success) {
                            $('.closte_pending_task[data-task-id="' + closte_task_id + '"]').replaceWith('<span>Success</span>');
                        } else {
                            $('.closte_pending_task[data-task-id="' + closte_task_id + '"]').replaceWith('<span>Failed</span>');
                        }


                    }

                }
            },
            error: function (request, status, error) {
                clearInterval(closteCheckStatusInterval);
            }
        });

       
    }

    $(document).on("click", "#closte_do_backup", function () {


        $(this).replaceWith("<img src='/wp-admin/images/loading.gif'>");

        var data = {
            'action': 'closte_create_backup',
        };

        jQuery.post(ajaxurl, data, function (response) {
           
             if (response.success) {
                 location.reload();
             }

        });
    });

  
});