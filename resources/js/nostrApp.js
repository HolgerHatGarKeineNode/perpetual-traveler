import {ndkInstance} from "./ndk/instance.js";

export default (livewireComponent) => ({

    async initNDK() {
        const instance = await ndkInstance(this).init();

        console.log('##### instance #####', instance);

        await livewireComponent.call('login', instance._activeUser._npub);
    },


});
