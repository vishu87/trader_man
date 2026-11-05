import axios from 'axios';

export default class DBService {

    static async getData(url){

        var promise = axios.get(base_url+url, {
            headers : {
                apiToken : api_key
            }
        })
        .then(function (response) {
            if(response.status == 200){
                var obj = response.data;
                if (!("success" in obj)) {
                  obj.success = true;
                }
                return obj;
            } else {
                return {
                    success : false,
                    message : response.data.message,
                    errors : response.data.errors
                }
            }
        })
        .catch(function (error) {
            return {
                success : false,
                message : error
            }
        })

        return promise;

    }

    static async postData(url, data){

        var promise = axios.post(
            base_url + url,
            data,
            {
                headers : {
                    apiToken : api_key
                }
            }
        )
        .then(function (response) {
            if(response.status == 200){
                var obj = response.data;
                if (!("success" in obj)) {
                  obj.success = true;
                }
                return obj;
            } else {
                return {
                    success : false,
                    message : response.data.message,
                    errors : response.data.errors
                }
            }
        })
        .catch(function (error) {
            if(error.status == 422){
                return {
                    success : false,
                    message : error.response.data.message,
                    errors : error.response.data.errors
                }
            } else {
                return {
                    success : false,
                    message : error
                }
            }
        })

        return promise;
    }
}