<table style="font-size:10;" border="1">
    <tr>
        <td width="30" style="font-weight:bold;">Framework</td>
        <td width="50" style="font-weight:bold;">App</td>
        <td width="50" style="font-weight:bold;">Subcontrol</td>
        <td width="15" style="font-weight:bold;">Previous Score</td>
        <td width="15" style="font-weight:bold;">Current Score</td>
        <td width="25" style="font-weight:bold;">User</td>
        <td width="20" style="font-weight:bold;">Date</td>
    </tr>
    @if (!empty($data["sheet_data"]))
        @foreach($data["sheet_data"] as $score)
            <tr>
                <td>{{ $score->framework_name }}</td>
                <td>{{ $score->app_name }}</td>
                <td>{{ $score->sub_control_title }}</td>
                <td>{{ $score->old_value }}</td>
                <td>{{ $score->new_value }} </td>
                <td>{{ $score->scored_by }}</td>
                <td>{{ date("m/d/Y g:i a", strtotime($score->date)) }}</td>
            </tr>
        @endforeach
    @endif
</table>