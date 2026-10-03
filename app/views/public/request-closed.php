<?php /** The form is off. Data: address (string|null) */ ?>
<div class="card" id="request-closed"><div class="card-body text-center py-4">
    <h5 class="mb-2"><?= $address !== null ? 'Requests are taken by email at <a href="mailto:' . e($address) . '" id="request-closed-address">' . e($address) . '</a>.' : 'Requests are not taken here.' ?></h5>
</div></div>
