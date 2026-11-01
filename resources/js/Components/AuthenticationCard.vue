<template>
    <div class="bg-base-200 flex flex-col justify-center items-center px-5 py-8 sm:py-12">
        <div>
            <slot name="logo" />
        </div>

        <div class="ui-panel w-full sm:max-w-md mt-8 px-6 py-8 sm:px-9 text-base-content">
            <slot />
        </div>
    </div>
</template>
