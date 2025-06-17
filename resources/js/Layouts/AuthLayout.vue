<template>
    <div class="min-h-screen bg-gray-100 flex flex-col items-center justify-center py-12 px-4 sm:px-6 lg:px-8">
        <div class="w-full max-w-md space-y-8 bg-white p-8 rounded-xl shadow-xl border-t-8 border-yellow-500">
            <div class="text-center">
                <img src="/images/logo-bg.png" alt="Pathfinder Club" class="h-20 mx-auto mb-4" />
                <h2 class="text-2xl font-bold text-gray-900">
                    <slot name="title" />
                </h2>
                <p class="text-sm text-gray-600">
                    <slot name="subtitle">Prepared. Fit. Loyal. Pure.</slot>
                </p>
            </div>

            <!-- Content goes here -->
            <slot />
        </div>
    </div>
</template>