import {Alpine, Livewire} from '../../vendor/livewire/livewire/dist/livewire.esm';
import {NDKNip07Signer} from "@nostr-dev-kit/ndk";
import NDKCacheAdapterDexie from "@nostr-dev-kit/ndk-cache-dexie";
import nostrApp from "./nostrApp.js";
import nostrCal from "./nostrCal.js";

Alpine.store('ndk', {
    // current nostr user
    user: null,
    // hours ago
    explicitRelayUrls: [],
});
Alpine.data('nostrApp', nostrApp);
Alpine.data('nostrCal', nostrCal);

Livewire.start();
