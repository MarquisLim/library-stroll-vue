<template>
    <div class="relative">
        <div class="flex flex-wrap gap-2 p-2 bg-gray-700 rounded">
            <span v-for="(t,i) in tags" :key="i" class="bg-purple-500 text-white px-2 py-1 rounded flex items-center space-x-1">
                <span>{{ t }}</span>
                <button type="button" class="text-white font-bold" @click="removeTag(i)">&times;</button>
            </span>
            <input
                class="bg-gray-800 text-white px-2 py-1 rounded flex-1 min-w-0"
                type="text"
                :placeholder="placeholder"
                v-model="text"
                @input="onInput"
                @keydown.enter.prevent="onEnter"
            />
        </div>

        <!-- Подсказки для тегов -->
        <div v-if="showSuggestions" class="absolute bg-gray-800 border border-gray-600 rounded w-full mt-1 max-h-40 overflow-auto z-50">
            <div v-for="(s,i) in suggestions" :key="i"
                 class="px-2 py-1 hover:bg-gray-600 cursor-pointer"
                 @click="selectSuggestion(s)">
                {{ s }}
            </div>
        </div>
    </div>
</template>

<script>
export default {
    props: {
        initialTags: { type: Array, default: () => [] },
        placeholder: { type: String, default: 'Введите тег...' },
        suggestions: { type: Array, default: () => [] }
    },
    data() {
        return {
            text: '',
            tags: [...this.initialTags]
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
            if (val !== '' && !this.tags.includes(val)) {
                this.tags.push(val)
                this.text = ''
                this.$emit('tagsUpdated', this.tags)
                this.$emit('addTag', val)
            }
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
.tags-input {
    display: flex;
    flex-wrap: wrap;
    border: 1px solid #ccc;
    padding: 5px;
    border-radius: 4px;
}

.tag {
    background-color: #e2e8f0;
    border-radius: 3px;
    padding: 2px 5px;
    margin: 2px;
    display: flex;
    align-items: center;
}

.tag span {
    margin-left: 5px;
    cursor: pointer;
}

input {
    border: none;
    outline: none;
    flex: 1;
    min-width: 100px;
}
</style>
