<!DOCTYPE html>
<html>
<head>
    <title>{{config('idoc.title')}}</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <style>
        @import url(//fonts.googleapis.com/css?family=Roboto:400,700);

        body {
            margin: 0;
            padding: 0;
            font-family: Verdana, Geneva, sans-serif;
        }

        #redoc_container .menu-content img {
            padding: 0px 0px 30px 0px;
        }
    </style>
    <link rel="icon" type="image/png" href="/favicon.ico">
    <link rel="apple-touch-icon-precomposed" href="/favicon.ico">
</head>
<body>
<div id="redoc_container"></div>
<script src="https://cdn.jsdelivr.net/npm/redoc@2.0.0-rc.58/bundles/redoc.standalone.js"></script>

<script>
    Redoc.init("{{config('idoc.output') . "/openapi.json"}}", {
            "theme": {
                "layout": {
                    "showDarkRightPanel": false,
                },
                "logo": {
                    "gutter": "300px",
                    "maxHeight": "150px"
                },
                "sidebar": {
                    "width": "300px",
                    "textColor": "#000000",
                },
                "rightPanel": {
                    "backgroundColor": "rgba(25, 53, 71, 1)",
                    "width": "700px",
                    "textColor": "#ffffff"
                }
            },
            "showConsole": true,
            "pathInMiddlePanel": true,
            "redocExport": "RedocPro",
            "layout": {"scope": "section"},
            "unstable_externalDescription": '{{route('idoc.info')}}',
            "hideDownloadButton": {{config('idoc.hide_download_button') ?: 0}}
        },
        document.getElementById("redoc_container")
    );

    var constantMock = window.fetch;
    window.fetch = function () {

        if (/\/api/.test(arguments[0]) && !arguments[1].headers.Accept) {
            arguments[1].headers.Accept = 'application/json';
        }

        return constantMock.apply(this, arguments)
    }
</script>
</body>
</html>
