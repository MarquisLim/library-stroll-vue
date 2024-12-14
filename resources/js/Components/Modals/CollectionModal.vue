<template>
    <div class="modal modal-open">
        <div class="modal-box">
            <h3 class="font-bold text-lg">Новая коллекция</h3>
            <div class="my-4">
                <label class="block mb-1">Название коллекции</label>
                <input type="text" class="input input-bordered w-full" v-model="name">

                <label class="flex items-center space-x-2 mt-2">
                    <input type="checkbox" class="checkbox checkbox-primary" v-model="is_private">
                    <span>Приватная коллекция</span>
                </label>
            </div>
            <div class="modal-action">
                <button class="btn" @click="$emit('close')">Отмена</button>
                <button class="btn btn-primary" @click="createCollection">Создать</button>
            </div>
        </div>
    </div>
</template>

<script>
import { Inertia } from '@inertiajs/inertia'

export default {
    data(){
        return {
            name:'',
            is_private:false
        }
    },
    methods:{
        createCollection(){
            Inertia.post(route('studio.createCollection'), {
                name: this.name,
                is_private: this.is_private
            }, {
                onSuccess: (page) => {
                    // Предположим, что backend возвращает новую коллекцию в props: page.props.newCollection
                    const newCollection = page.props.newCollection;
                    this.$emit('collectionCreated', newCollection)
                    this.$emit('close')
                }
            })
        }
    }
}
</script>
