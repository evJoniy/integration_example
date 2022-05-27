<!DOCTYPE HTML>
<html>
<head>
    <meta charset="utf-8">
    <title>MTA Integration</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
</head>
<body style="background-color: #313030; color: white">
<div style="text-align: center;">
    <h3>Do not press anything unless you aware of consequences</h3>
    <h3><a class='btn btn-link' href='https://github.com/evJoniy/integration_example/blob/main/readme.md' style="color: white">Read READ.ME first (link)</a></h3>
    <h3 id="counter" style="display: none"></h3>
</div>
<div id="main" style="width: 200px; margin-left: 50%; transform: translateX(-50%)">
    <input type="submit" class="button" id="abtesting" value="Set AB for exists"/>
    <input type="submit" class="button" id="create" value="Create contacts from DB"/>
</div>
</body>
</html>

<script>
    $(document).ready(function () {
        $('.button').click(function () {
            let id = $(this).attr('id');

            $.ajax({
                type: "POST",
                url: "backend.php",
                data: {action: id},
                success: async function (response) {
                    console.log(JSON.parse(response))
                }
            });
        });

        $.ajax({
            type: "POST",
            url: "backend.php",
            data: {action: 'count'},
            success: async function (response) {
                document.getElementById('counter').innerText = 'Current lead count: ' + response;
                document.getElementById('counter').style = 'block';
            }
        });
    });
</script>

<style>
    .button {
        width: 200px;
        height: 30px;
        margin: 10px 0;
        box-sizing: border-box;
    }
</style>