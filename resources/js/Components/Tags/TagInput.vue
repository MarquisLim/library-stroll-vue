<template>
    <div class="relative">
        <div
            class="flex flex-wrap gap-2 p-2
             bg-base-200 dark:bg-base-700
             border border-base-300 dark:border-base-600
             rounded-lg"
        >
      <span
          v-for="(t, i) in tags"
          :key="i"
          class="flex items-center space-x-1
               bg-primary text-primary-content
               px-2 py-1 rounded-full"
      >
        <span class="truncate max-w-xs">{{ t }}</span>
        <button
            type="button"
            class="text-primary-content hover:text-primary-focus"
            @click="removeTag(i)"
        >&times;</button>
      </span>

            <!-- вот тут поменяли -->
            <input
                v-model="text"
                @input="onInput"
                @keydown.enter.prevent="onEnter"
                :placeholder="placeholder"
                class="
                        flex-1 min-w-[100px]
                        bg-transparent
                        px-2 py-1 rounded-lg
                        border border-base-300 dark:border-base-600
                        focus:border-primary focus:ring-2 focus:ring-primary
                        outline-none
                        text-base-content
                        placeholder-base-content placeholder-opacity-50
                        dark:placeholder-base-content dark:placeholder-opacity-50
                    "
            />
        </div>

        <ul
            v-if="showSuggestions"
            class="absolute z-50 w-full mt-1
             bg-base-200 dark:bg-base-700
             border border-base-300 dark:border-base-600
             rounded-lg overflow-auto max-h-40"
        >
            <li
                v-for="(s, i) in suggestions"
                :key="i"
                class="px-3 py-2 hover:bg-base-300 dark:hover:bg-base-600
               cursor-pointer
               text-base-content"
                @click="selectSuggestion(s)"
            >
                {{ s }}
            </li>
        </ul>
    </div>
</template>

<script>
export default {
    props: {
        initialTags:    { type: Array,   default: () => [] },
        placeholder:    { type: String,  default: 'Введите тег...' },
        suggestions:    { type: Array,   default: () => [] },
    },
    data() {
        return {
            text: '',
            tags: [...this.initialTags],
        }
    },
    computed: {
        showSuggestions() {
            return this.suggestions.length > 0 && this.text.trim() !== ''
        }
    },
    watch: {
        initialTags(newTags) {
            this.tags = [...newTags]
        }
    },
    methods: {
        onInput() {
            this.$emit('search', this.text.trim())
        },
        onEnter() {
            const val = this.text.trim()
            if (val && !this.tags.includes(val)) {
                this.tags.push(val)
                this.$emit('tagsUpdated', this.tags)
                this.$emit('addTag', val)
            }
            this.text = ''
        },
        selectSuggestion(s) {
            if (!this.tags.includes(s)) {
                this.tags.push(s)
                this.$emit('tagsUpdated', this.tags)
                this.$emit('addTag', s)
            }
            this.text = ''
        },
        removeTag(i) {
            this.tags.splice(i, 1)
            this.$emit('tagsUpdated', this.tags)
        }
    }
}
</script>

<style scoped>
/* Всё остальное осталось через Tailwind/daisyUI, никаких дополнительных стилей не нужно */
</style>
