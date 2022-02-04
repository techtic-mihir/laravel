
<table>
    <tr><td></td><td></td>
@foreach($data['dates'] as $row)
<td height='40' width="10">{{$row}}</td>
@endforeach
    </tr>
@foreach($data['input'] as $row)
    <tr><td height='20' width="40"><strong>{{$row["name"]}}</strong></td></tr>
    @if (isset($row["users"]) && !empty($row["users"]))
        @foreach($row["users"] as $user)
        <tr><td height='20'  width="30">{{$user["name"]}}</td>
            <td height='20'  width="30"><a href="mailto:{{$user["name"]}}">{{$user["email"]}}</a></td>
            @foreach($user["login"] as $login)
                <td>{{$login}}</td>
            @endforeach
        @endforeach
        </tr>
    @else
        <tr><td height='20'>No Users</td></tr>
    @endif
   <tr><td height='20'>&nbsp;</td></tr>
   
@endforeach 
</table>
