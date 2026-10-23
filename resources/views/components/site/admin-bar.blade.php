{{--
    The staff bar on the public site.

    Shown only to a signed-in, active member of staff, so an admin browsing the
    storefront can jump straight back to the panel (the WordPress admin-bar
    convention). Customers never see it — isStaff() is the absence of the §8 admin
    roles — and a deactivated leaver, who can no longer reach the panel, does not
    get a link that would only bounce them.
--}}
@php($staff = auth()->user())
@if ($staff !== null && $staff->isStaff() && ! $staff->isDeactivated())
    <div class="admin-bar">
        <div class="admin-bar-inner">
            <span class="admin-bar-tag">
                <x-ui.icon name="shield-check" :size="14" />
                Admin view
            </span>
            <span class="admin-bar-who">Signed in as <strong>{{ $staff->name }}</strong> · {{ $staff->describeRoles() }}</span>
            <a class="admin-bar-btn" href="{{ url('/admin') }}">Admin dashboard <span aria-hidden="true">&rarr;</span></a>
        </div>
    </div>
@endif
