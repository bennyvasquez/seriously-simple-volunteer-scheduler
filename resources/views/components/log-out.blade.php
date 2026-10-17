<form method="POST" action="{{ route('logout') }}">
@csrf
<button type="submit" class="btn">Log out</button>
</form>
