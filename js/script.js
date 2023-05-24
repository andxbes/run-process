/*
 * To change this license header, choose License Headers in Project Properties.
 * To change this template file, choose Tools | Templates
 * and open the template in the editor.
 */


jQuery(document).ready(function ($) {
//    console.info(rce_params, all_processparts);
    var all_Users = all_processparts.length;
    var part_size = rce_params.part_size;
    var $button_new_process_sending = $("#run_resending_email");
    var $button_stop = $("#resending_pause");
    var $error_text = $('#resce-error');
    var stop = false;

    $button_stop.prop("disabled", true);


    moveProgressBar();
// on browser resize...
    $(window).resize(function () {
        moveProgressBar();
    });

// SIGNATURE PROGRESS
    function moveProgressBar() {
        console.log("moveProgressBar");
        var getPercent = ($('.progress-wrap').attr('data-progress-percent') / 100);
        var getProgressWrapWidth = $('.progress-wrap').width();
        var progressTotal = getPercent * getProgressWrapWidth;
        var animationLength = 2500;

        // on page load, animate percentage bar to data percentage length
        // .stop() used to prevent animation queueing
        $('.progress-bar').stop().animate({
            left: progressTotal
        }, animationLength);
    }
    function change_progress_bar(residue) {
        percent = (100 - ((residue / all_Users) * 100));
        $('.progress-wrap').attr("data-progress-percent", percent);
        $('#resce-allEmails').text(residue);
        moveProgressBar();
    }


    $button_new_process_sending.click(function () {
        $button_new_process_sending.prop("disabled", true);
        stop = false;
        send_part(generate_parts(part_size));
    });

    $button_stop.click(function () {
        stop = true;

    });

    function generate_parts(num = 10) {
        var part_user_ids = [];
        if(all_processparts.length > 0){
            var allUser = all_processparts.length;
            for (i = 0; i < allUser && i < num; i++) {
                part_user_ids.push(all_processparts.pop());
            }
        }
        return part_user_ids;
    }

    function send_part(processparts) {
        $error_text.text('');
        $button_stop.prop("disabled", false);
        $.ajax({
            type: "POST",
            dataType: "json",
            url: rce_params.ajaxurl,
            data: {
                action: "sending_part",
                process_part_ids: processparts,
                security: rce_params.ajax_nonce,

            }, //'action=get_posts_commented&amp;email=' + user_email + '&amp;security=' + rce_params.ajax_nonce,
            success: function (response) {

                if (response.success === true) {
                    console.info(response.data);
                    for(var key in response.data) {
                        $error_text.after("<p>"+key+" - "+response.data[key]+"</p>");
                    }

                    change_progress_bar(all_processparts.length);
                    if (all_processparts.length > 0 && !stop) {
                        send_part(generate_parts(part_size));
                    } else {
                        if (stop) {
                            error_instruction();
                        } else {
                            $button_new_process_sending.text("Готово");
                            send_finish_import();
                        }
                        $button_stop.prop("disabled", true);
                    }
                } else {
                    all_processparts.shift(processparts);
                    console.error(response);
                    return_users(processparts);
                    error_instruction();
                    $error_text.text("Произошла ошибка \n" + JSON.stringify(response));

                }
            },
            error: function (jqXHR, textStatus) {
                all_processparts.shift(processparts);
                console.error(jqXHR);
                return_users(processparts);
                error_instruction();
                $error_text.text("Сервер вернул критическую ошибку \n " + JSON.stringify(jqXHR));
            }
        });
    }

    function send_finish_import(){
        $.ajax({
            type: "POST",
            dataType: "json",
            url: rce_params.ajaxurl,
            data: {
                action: "process_is_finish",
                security: rce_params.ajax_nonce,

            }, //'action=get_posts_commented&amp;email=' + user_email + '&amp;security=' + rce_params.ajax_nonce,
            success: function (response) {

                if (response.success === true) {
                    console.info(response.data);
                }
            },
            error: function (jqXHR, textStatus) {
                console.error(jqXHR);
                return_users(processparts);
                error_instruction();
                $error_text.text("Сервер вернул критическую ошибку \n " + JSON.stringify(jqXHR));
            }
        });
    }

    function error_instruction() {
        $button_stop.prop("disabled", true);
        $button_new_process_sending.prop("disabled", false);
        $button_new_process_sending.text("Продолжить");
    }

    function return_users(users) {
        for (i = 0; i < users.length; i++) {
            all_processparts.push(users[i]);
        }
    }


})

