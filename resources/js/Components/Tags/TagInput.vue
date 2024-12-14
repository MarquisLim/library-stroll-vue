<template>
    <div>
        <TagsInput
            v-model="text"
            :tags="tags"
            placeholder="Введите тег..."
            @update:tags="tagsChanged"
        />
    </div>
</template>

<script>
import VueTagsInput from 'vue3-tags-input'

export default {
    components: { TagsInput: VueTagsInput },
    props: {
        initialTags: {
            type: Array,
            default: () => []
        }
    },
    data() {
        return {
            text: '', // текущий текст ввода
            tags: [...this.initialTags] // копия массива тегов
        }
    },
    methods: {
        tagsChanged(newTags) {
            this.tags = newTags
            this.$emit('tagsUpdated', newTags)
        }
    }
}
</script>
